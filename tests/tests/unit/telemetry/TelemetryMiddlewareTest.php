<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\TelemetryMiddleware;
use Quiote\Telemetry\NoopSpanHandle;
use Quiote\Telemetry\SpanKind;
use Quiote\Telemetry\TelemetryBootstrap;
use Quiote\Telemetry\Trace;

/**
 * Phase 3 tests for TelemetryMiddleware: the root request span plus the
 * headline resource measurements (wall time, CPU, memory), and every failure
 * path — downstream exceptions, telemetry disabled — degrading safely.
 */
class TelemetryMiddlewareTest extends TestCase
{
    #[Before]
    public function setUpTelemetry(): void
    {
        TelemetryBootstrap::reset();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        TelemetryBootstrap::reset();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
        Config::remove('telemetry.sampling.ratio');
        Config::remove('telemetry.sampling.force_header');
    }

    private function enable(): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true); // synchronous export on span end
        // Pinned to always_on: these tests assert on span/metric content, not
        // sampling behavior (Phase 4's own ratio/force-sample tests live in
        // TelemetrySamplingTest.php) — the default ratio sampler would make
        // span capture here nondeterministic.
        Config::set('telemetry.sampling.strategy', 'always_on', true);
        TelemetryBootstrap::configureFromConfig();
    }

    /** A terminal handler that returns a fixed response and captures the request it saw. */
    private function terminal(ResponseInterface $response, ?callable $onHandle = null): RequestHandlerInterface
    {
        return new class($response, $onHandle) implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;
            public function __construct(private ResponseInterface $response, private $onHandle) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;
                if ($this->onHandle) {
                    ($this->onHandle)($request);
                }
                return $this->response;
            }
        };
    }

    private function exportedSpans(): array
    {
        return TelemetryBootstrap::inMemorySpanExporter()->getSpans();
    }

    private function exportedMetricNames(): array
    {
        TelemetryBootstrap::flushAfterRequest();
        return array_map(static fn($m) => $m->name, TelemetryBootstrap::inMemoryMetricExporter()->collect());
    }

    // --- disabled: pure pass-through ------------------------------------------

    public function testDisabledIsAPureNoopPassThrough(): void
    {
        $mw = new TelemetryMiddleware();
        $response = new Psr7Response(200);
        $request = new ServerRequest('GET', '/foo');

        $result = $mw->process($request, $this->terminal($response));

        $this->assertSame($response, $result);
        $this->assertFalse(Trace::enabled());
    }

    // --- happy path -------------------------------------------------------------

    public function testOpensServerSpanNamedByMethodAndPath(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();
        $request = new ServerRequest('GET', 'http://localhost/orders/42');

        $mw->process($request, $this->terminal(new Psr7Response(200)));

        $spans = $this->exportedSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('GET /orders/42', $spans[0]->getName());
        $this->assertSame(SpanKind::Server->value, $spans[0]->getKind());

        $attrs = iterator_to_array($spans[0]->getAttributes());
        $this->assertSame('GET', $attrs['http.request.method']);
        $this->assertSame('/orders/42', $attrs['url.path']);
    }

    public function testRecordsStatusCodeAttribute(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('POST', '/orders'), $this->terminal(new Psr7Response(201)));

        $attrs = iterator_to_array($this->exportedSpans()[0]->getAttributes());
        $this->assertSame(201, $attrs['http.response.status_code']);
    }

    public function testRecordsDurationAndMemoryAttributes(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(200)));

        $attrs = iterator_to_array($this->exportedSpans()[0]->getAttributes());
        $this->assertArrayHasKey('quiote.duration_ms', $attrs);
        $this->assertGreaterThanOrEqual(0.0, $attrs['quiote.duration_ms']);
        $this->assertArrayHasKey('quiote.memory.peak_bytes', $attrs);
        $this->assertGreaterThan(0, $attrs['quiote.memory.peak_bytes']);
        $this->assertArrayHasKey('quiote.memory.delta_bytes', $attrs);
    }

    public function testRecordsCpuAttributesWhenGetrusageAvailable(): void
    {
        if (!function_exists('getrusage')) {
            $this->markTestSkipped('getrusage() not available on this platform');
        }
        $this->enable();
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(200)));

        $attrs = iterator_to_array($this->exportedSpans()[0]->getAttributes());
        $this->assertArrayHasKey('quiote.cpu.user_ms', $attrs);
        $this->assertArrayHasKey('quiote.cpu.system_ms', $attrs);
        $this->assertGreaterThanOrEqual(0.0, $attrs['quiote.cpu.user_ms']);
        $this->assertGreaterThanOrEqual(0.0, $attrs['quiote.cpu.system_ms']);
    }

    public function testRecordsResponseBodySizeWhenKnown(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();
        $body = \Nyholm\Psr7\Stream::create('hello world');

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal((new Psr7Response(200))->withBody($body)));

        $attrs = iterator_to_array($this->exportedSpans()[0]->getAttributes());
        $this->assertSame(11, $attrs['http.response.body.size']);
    }

    public function testRecordsCacheHitFromSharedExecutionState(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        // Mirrors how DispatchMiddleware marks a cache hit on the SAME
        // ExecutionState object TelemetryMiddleware seeded before calling the
        // handler (not a PSR-7 request attribute, which downstream mutations
        // to a cloned request would never propagate back up).
        $handler = $this->terminal(new Psr7Response(200), function (ServerRequestInterface $req): void {
            $req->getAttribute(ExecutionState::class)->cacheHit = true;
        });

        $mw->process(new ServerRequest('GET', '/x'), $handler);

        $attrs = iterator_to_array($this->exportedSpans()[0]->getAttributes());
        $this->assertTrue($attrs['quiote.cache.hit']);
    }

    public function testCacheHitDefaultsFalse(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(200)));

        $attrs = iterator_to_array($this->exportedSpans()[0]->getAttributes());
        $this->assertFalse($attrs['quiote.cache.hit']);
    }

    public function testCurrentSpanIsActiveWhileHandlerRuns(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        $sawRealSpan = false;
        $handler = $this->terminal(new Psr7Response(200), function () use (&$sawRealSpan): void {
            $sawRealSpan = !(Trace::current() instanceof NoopSpanHandle);
        });

        $mw->process(new ServerRequest('GET', '/x'), $handler);

        $this->assertTrue($sawRealSpan, 'Trace::current() must reflect the activated root span while the handler runs, so nested spans can parent onto it');
    }

    public function testMetricsAreRecorded(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(200)));

        $names = $this->exportedMetricNames();
        $this->assertContains('http.server.request.duration', $names);
        $this->assertContains('quiote.request.memory.peak', $names);
        $this->assertContains('quiote.worker.memory.rss', $names);
        $this->assertContains('http.server.request.count', $names);
        if (function_exists('getrusage')) {
            $this->assertContains('quiote.request.cpu.time', $names);
        }
    }

    public function testMemoryPeakIsResetPerRequestNotProcessCumulative(): void
    {
        if (!function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('memory_reset_peak_usage() not available on this platform');
        }
        $this->enable();
        $mw = new TelemetryMiddleware();

        // Request 1: downstream handler inflates the peak with a large allocation.
        $mw->process(new ServerRequest('GET', '/heavy'), $this->terminal(new Psr7Response(200), function (): void {
            $hog = str_repeat('x', 20 * 1024 * 1024); // ~20MB
            unset($hog);
        }));
        $firstPeak = iterator_to_array($this->exportedSpans()[0]->getAttributes())['quiote.memory.peak_bytes'];

        // Request 2: a cheap handler with no comparable allocation. Without the
        // per-request memory_reset_peak_usage() call, memory_get_peak_usage()
        // is monotonic for the whole process and this would still report
        // (approximately) request 1's inflated peak.
        $mw->process(new ServerRequest('GET', '/cheap'), $this->terminal(new Psr7Response(200)));
        $secondPeak = iterator_to_array($this->exportedSpans()[1]->getAttributes())['quiote.memory.peak_bytes'];

        $this->assertLessThan($firstPeak, $secondPeak, 'peak must reset between requests, not accumulate for the whole worker process');
    }

    // --- failure paths -----------------------------------------------------------

    public function testExceptionIsRecordedOnSpanAndRethrown(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();
        $handler = $this->terminal(new Psr7Response(200), function (): void {
            throw new \RuntimeException('downstream exploded');
        });

        $caught = null;
        try {
            $mw->process(new ServerRequest('GET', '/x'), $handler);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'the exception must propagate to ErrorHandlingMiddleware further out, not be swallowed');
        $this->assertSame('downstream exploded', $caught->getMessage());

        $spans = $this->exportedSpans();
        $this->assertCount(1, $spans, 'the root span must still be exported even though the request failed');
        $this->assertSame('Error', $spans[0]->getStatus()->getCode());
        $this->assertNotEmpty($spans[0]->getEvents(), 'recordException() must add an exception event');
    }

    public function testFiveHundredResponseSetsErrorStatusWithoutAnException(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(500)));

        $spans = $this->exportedSpans();
        $this->assertSame('Error', $spans[0]->getStatus()->getCode());
    }

    public function testFourHundredResponseDoesNotSetErrorStatus(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(404)));

        $spans = $this->exportedSpans();
        $this->assertNotSame('Error', $spans[0]->getStatus()->getCode());
    }

    public function testPreExistingExecutionStateIsReusedNotReplaced(): void
    {
        $this->enable();
        $mw = new TelemetryMiddleware();
        $exec = new ExecutionState();
        $exec->cacheHit = true;
        $request = (new ServerRequest('GET', '/x'))->withAttribute(ExecutionState::class, $exec);

        $handler = $this->terminal(new Psr7Response(200), function (ServerRequestInterface $req) use ($exec): void {
            // Must be the exact same object, not a fresh ExecutionState.
            \PHPUnit\Framework\Assert::assertSame($exec, $req->getAttribute(ExecutionState::class));
        });

        $mw->process($request, $handler);

        $attrs = iterator_to_array($this->exportedSpans()[0]->getAttributes());
        $this->assertTrue($attrs['quiote.cache.hit']);
    }

    // --- Phase 4: sampling integration through the middleware -------------------

    private function enableWithRatio(float $ratio): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', 'parentbased_traceidratio', true);
        Config::set('telemetry.sampling.ratio', $ratio, true);
        TelemetryBootstrap::configureFromConfig();
    }

    public function testForceSampleHeaderBypassesAZeroRatio(): void
    {
        $this->enableWithRatio(0.0);
        $mw = new TelemetryMiddleware();
        $request = (new ServerRequest('GET', '/x'))->withHeader('X-Quiote-Trace', '1');

        $mw->process($request, $this->terminal(new Psr7Response(200)));

        $this->assertCount(1, $this->exportedSpans(), 'the force-sample header must override the 0.0 ratio for this one request');
    }

    public function testConfiguredForceSampleHeaderNameIsHonored(): void
    {
        Config::set('telemetry.sampling.force_header', 'X-Debug-Trace-Me', true);
        $this->enableWithRatio(0.0);
        $mw = new TelemetryMiddleware();
        $request = (new ServerRequest('GET', '/x'))->withHeader('X-Debug-Trace-Me', 'true');

        $mw->process($request, $this->terminal(new Psr7Response(200)));

        $this->assertCount(1, $this->exportedSpans());
        Config::remove('telemetry.sampling.force_header');
    }

    public function testForceSampleRequestAttributeBypassesAZeroRatio(): void
    {
        $this->enableWithRatio(0.0);
        $mw = new TelemetryMiddleware();
        $request = (new ServerRequest('GET', '/x'))->withAttribute('quiote.force_sample', true);

        $mw->process($request, $this->terminal(new Psr7Response(200)));

        $this->assertCount(1, $this->exportedSpans());
    }

    public function testWithoutAnyForceSignalAZeroRatioDropsTheRootSpan(): void
    {
        $this->enableWithRatio(0.0);
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(200)));

        $this->assertCount(0, $this->exportedSpans());
    }

    public function testMetricsAreRecordedEvenWhenTheSpanIsDropped(): void
    {
        // The plan's central claim, exercised at the actual integration point:
        // a 0.0 ratio drops the trace but must never suppress metrics.
        $this->enableWithRatio(0.0);
        $mw = new TelemetryMiddleware();

        $mw->process(new ServerRequest('GET', '/x'), $this->terminal(new Psr7Response(200)));

        $this->assertCount(0, $this->exportedSpans(), 'sanity check: the span really was dropped');
        $names = $this->exportedMetricNames();
        $this->assertContains('http.server.request.duration', $names);
        $this->assertContains('quiote.request.memory.peak', $names);
        $this->assertContains('http.server.request.count', $names);
    }
}
