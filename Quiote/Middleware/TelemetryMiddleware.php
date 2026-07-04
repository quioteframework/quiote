<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Config\Config;
use Quiote\Execution\ExecutionState;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogContext;
use Quiote\Telemetry\Psr7HeaderGetter;
use Quiote\Telemetry\SpanHandle;
use Quiote\Telemetry\SpanKind;
use Quiote\Telemetry\Trace;

/**
 * Opens the root OpenTelemetry span for the request and records the headline
 * resource measurements — wall time, CPU, memory — as both span attributes
 * and OTel metrics. Also carries the force-sample signal into the span's
 * creation-time attributes,
 * since a sampler can only see attributes present when the span is created.
 *
 * Extracts an inbound W3C `traceparent`/`tracestate` so this request
 * joins an upstream distributed trace instead of always starting a new one,
 * and enriches {@see LogContext} with the root span's trace/span IDs so every
 * log line during the request is cross-navigable with the trace — this works
 * even for a sampled-out span, since IDs exist independent of the sampling
 * decision.
 *
 * A no-op (just calls `$handler->handle($request)`) whenever
 * {@see Trace::enabled()} is false, so this middleware is always safe to
 * leave in the default pipeline regardless of whether telemetry is on.
 *
 * Positioned just inside ErrorHandlingMiddleware (priority 1000 vs this
 * class's 950 — higher priority runs more outward, see
 * `MiddlewareOrderResolver`) so an uncaught exception passes through this
 * middleware's own try/catch first (recording it on the root span, then
 * re-throwing) before ErrorHandlingMiddleware renders the error response
 * further out.
 *
 * Deliberately does NOT attempt route/action attribution (`http.route`,
 * `module`/`action`) here: that information is only known to inner
 * middleware (RoutingMiddleware/DispatchMiddleware) operating on their own
 * PSR-7 request clone, which this outer middleware never sees back per PSR-7
 * immutability. Enriching the root span with route/action dimensions is
 * left to the middleware that already touches RoutingMiddleware directly.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 950)]
class TelemetryMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Trace::enabled()) {
            return $handler->handle($request);
        }

        $exec = $request->getAttribute(ExecutionState::class) ?? new ExecutionState();
        $request = $request->withAttribute(ExecutionState::class, $exec);

        $propagationScope = self::extractInboundContext($request);

        $wallStart = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $cpuStart = self::cpuTimes();
        if (function_exists('memory_reset_peak_usage')) {
            // Worker mode: memory_get_peak_usage() is monotonic for the process
            // lifetime, so without this reset every request would report the
            // all-time peak instead of its own.
            memory_reset_peak_usage();
        }
        $memoryStart = memory_get_usage(true);

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $attributes = [
            'http.request.method' => $method,
            'url.path' => $path,
        ];
        if (self::shouldForceSample($request)) {
            // Read by ForceSampleSampler at span-creation time — sampling
            // decisions can only see attributes present at creation, not ones
            // added later via setAttribute().
            $attributes['quiote.force_sample'] = true;
        }
        // If a valid traceparent was extracted above, this span's parent is
        // the upstream span (Trace::span() defaults to the current context,
        // which extractInboundContext() just activated).
        $span = Trace::span('Quiote.Http', $method . ' ' . $path, $attributes, SpanKind::Server);
        self::correlateLogContext($span);

        $statusCode = null;
        $error = null;
        try {
            $response = $handler->handle($request);
            $statusCode = $response->getStatusCode();
            return $response;
        } catch (\Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            if ($error !== null) {
                $span->recordException($error)->setStatusError($error->getMessage());
            }
            $responseSize = isset($response) ? $response->getBody()->getSize() : null;
            self::recordMeasurements($span, $wallStart, $cpuStart, $memoryStart, $statusCode, $responseSize, $exec->cacheHit);
            $span->end();
            $propagationScope?->detach();
        }
    }

    /**
     * Extracts a W3C traceparent/tracestate from the request and activates it
     * as the current context, so the root span opened right after this
     * parents onto the upstream span instead of starting a fresh trace.
     * Returns null (nothing to detach later) when there's no valid
     * traceparent header — {@see \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::extract()}
     * returns the exact same context instance unchanged in that case, so
     * comparing identity tells us whether activation is actually needed.
     */
    private static function extractInboundContext(ServerRequestInterface $request): ?\OpenTelemetry\Context\ScopeInterface
    {
        try {
            $current = \OpenTelemetry\Context\Context::getCurrent();
            $extracted = \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance()
                ->extract($request, new Psr7HeaderGetter(), $current);
            if ($extracted === $current) {
                return null;
            }
            return $extracted->activate();
        } catch (\Throwable $e) {
            if (Log::for(self::class)->isEnabled(Level::Debug)) {
                Log::for(self::class)->debug('[TelemetryMiddleware] inbound trace context extraction failed: ' . $e::class . ': ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Enriches LogContext with the root span's trace/span IDs so every log
     * line emitted during this request is cross-navigable with the trace,
     * mirroring how Context::handle() already enriches with `rid`. Done here
     * rather than in Context::handle() because at that point in the pipeline
     * no span exists yet — Context::handle() runs before the middleware
     * pipeline even starts, so TelemetryMiddleware is the earliest point a
     * real span is available.
     */
    private static function correlateLogContext(SpanHandle $span): void
    {
        $traceId = $span->traceId();
        if ($traceId === null) {
            return;
        }
        $data = ['trace_id' => $traceId];
        $spanId = $span->spanId();
        if ($spanId !== null) {
            $data['span_id'] = $spanId;
        }
        LogContext::enrich($data);
    }

    /**
     * Head-based force-sample escape hatch: "trace this one request" without
     * touching the global sampling ratio. Two signals, either one is enough: a PSR-7
     * `quiote.force_sample` request attribute (settable programmatically by
     * app/earlier-middleware code), or the configured header
     * (`telemetry.sampling.force_header`, default `X-Quiote-Trace`) set to a
     * truthy value.
     */
    private static function shouldForceSample(ServerRequestInterface $request): bool
    {
        if ($request->getAttribute('quiote.force_sample') === true) {
            return true;
        }
        $headerName = (string) Config::get('telemetry.sampling.force_header', 'X-Quiote-Trace');
        if ($headerName === '') {
            return false;
        }
        $value = strtolower($request->getHeaderLine($headerName));
        return in_array($value, ['1', 'true', 'yes'], true);
    }

    /** @return array{user: float, system: float}|null */
    private static function cpuTimes(): ?array
    {
        if (!function_exists('getrusage')) {
            return null;
        }
        $usage = getrusage();
        return [
            'user' => ($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_utime.tv_usec'] ?? 0) / 1_000_000,
            'system' => ($usage['ru_stime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0) / 1_000_000,
        ];
    }

    /**
     * @param array{user: float, system: float}|null $cpuStart
     */
    private static function recordMeasurements(
        SpanHandle $span,
        float $wallStart,
        ?array $cpuStart,
        int $memoryStart,
        ?int $statusCode,
        ?int $responseSize,
        bool $cacheHit,
    ): void {
        $durationSeconds = microtime(true) - $wallStart;
        $span->setAttribute('quiote.duration_ms', round($durationSeconds * 1000, 3));

        $cpuUserSeconds = null;
        $cpuSystemSeconds = null;
        $cpuEnd = self::cpuTimes();
        if ($cpuStart !== null && $cpuEnd !== null) {
            $cpuUserSeconds = max(0.0, $cpuEnd['user'] - $cpuStart['user']);
            $cpuSystemSeconds = max(0.0, $cpuEnd['system'] - $cpuStart['system']);
            $span->setAttribute('quiote.cpu.user_ms', round($cpuUserSeconds * 1000, 3));
            $span->setAttribute('quiote.cpu.system_ms', round($cpuSystemSeconds * 1000, 3));
        }

        $memoryPeak = memory_get_peak_usage(true);
        $memoryNow = memory_get_usage(true);
        $span->setAttribute('quiote.memory.peak_bytes', $memoryPeak);
        $span->setAttribute('quiote.memory.delta_bytes', $memoryNow - $memoryStart);
        $span->setAttribute('quiote.cache.hit', $cacheHit);

        if ($statusCode !== null) {
            $span->setAttribute('http.response.status_code', $statusCode);
            if ($statusCode >= 500) {
                $span->setStatusError();
            }
        }
        if ($responseSize !== null) {
            $span->setAttribute('http.response.body.size', $responseSize);
        }

        $metricAttributes = array_filter(
            ['http.response.status_code' => $statusCode, 'cache.hit' => $cacheHit],
            static fn($value): bool => $value !== null,
        );

        $meter = Trace::metrics();
        $meter->recordHistogram('http.server.request.duration', $durationSeconds, $metricAttributes);
        if ($cpuUserSeconds !== null && $cpuSystemSeconds !== null) {
            $meter->recordHistogram('quiote.request.cpu.time', $cpuUserSeconds, $metricAttributes + ['cpu.mode' => 'user']);
            $meter->recordHistogram('quiote.request.cpu.time', $cpuSystemSeconds, $metricAttributes + ['cpu.mode' => 'system']);
        }
        $meter->recordHistogram('quiote.request.memory.peak', (float) $memoryPeak, $metricAttributes);
        $meter->recordGauge('quiote.worker.memory.rss', (float) $memoryNow);
        $meter->addCounter('http.server.request.count', 1, $metricAttributes);
    }
}
