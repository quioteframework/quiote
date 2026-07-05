<?php

namespace Quiote\Telemetry;

use Quiote\Config\Config;
use Quiote\Logging\Log;
use Quiote\Runtime\Worker\FrankenPhpWorkerAdapter;

/**
 * Builds the worker-lifetime TracerProvider/MeterProvider from `telemetry.*`
 * settings, exactly once per worker process. Called unconditionally from
 * `Kernel::bootstrap()` — this class
 * itself decides whether there is anything to do, so callers never need a
 * feature-flag check of their own.
 *
 * Every path that can fail — telemetry disabled, the open-telemetry/sdk
 * package not installed, a bad exporter/endpoint config — degrades to
 * "telemetry stays off" rather than throwing, matching the plan's "not a hard
 * dependency" requirement. The OTLP exporter no longer needs an externally
 * installed PSR-18 client: it is handed Quiote's own zero-dependency
 * {@see \Quiote\Http\Client\CurlTransport} (see {@see otlpTransportFactory()}),
 * so `telemetry.exporter = otlp` works out of the box.
 */
final class TelemetryBootstrap
{
    private static bool $configured = false;
    private static bool $shutdownRegistered = false;

    private static ?\OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter $inMemorySpanExporter = null;
    private static ?\OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter $inMemoryMetricExporter = null;

    private function __construct() {}

    /**
     * Build the providers from config. Idempotent: a second call (e.g. a
     * second Kernel::bootstrap() in the same process) is a no-op and simply
     * reports whether a real provider is already active. Call {@see reset()}
     * first to force a rebuild (test isolation / simulating a fresh worker).
     *
     * @return bool true if a real, usable provider is now wired up.
     */
    public static function configureFromConfig(): bool
    {
        if (self::$configured) {
            return TraceRegistry::hasRealProvider();
        }
        self::$configured = true;

        if (!Config::getBool('telemetry.enabled', false)) {
            return false;
        }

        if (!class_exists(\OpenTelemetry\SDK\Trace\TracerProviderBuilder::class)) {
            Log::for(self::class)->warning(
                'telemetry.enabled is true but the open-telemetry/sdk package is not installed; '
                . 'telemetry stays disabled.'
            );
            return false;
        }

        try {
            $resource = self::buildResource();
            $tracerProvider = self::buildTracerProvider($resource);
            $meterProvider = self::buildMeterProvider($resource);

            TraceRegistry::setProviders($tracerProvider, $meterProvider);
            TraceRegistry::setEnabled(true);
            self::registerShutdown();

            return true;
        } catch (\Throwable $e) {
            Log::for(self::class)->error(
                '[TelemetryBootstrap] failed to configure telemetry, falling back to disabled: '
                . $e::class . ': ' . $e->getMessage()
            );
            TraceRegistry::setEnabled(false);
            return false;
        }
    }

    /**
     * Force-flush the active providers. Called at every worker request
     * boundary (Kernel's post-request reset closure) so each request's spans
     * and metrics are exported without tearing down the provider. Safe to
     * call when telemetry isn't configured (no-op).
     */
    public static function flushAfterRequest(): void
    {
        $tracerProvider = TraceRegistry::tracerProvider();
        $meterProvider = TraceRegistry::meterProvider();
        if ($tracerProvider === null && $meterProvider === null) {
            return;
        }
        try {
            $tracerProvider?->forceFlush();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] span flush failed: ' . $e::class . ': ' . $e->getMessage());
        }
        try {
            $meterProvider?->forceFlush();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] metric flush failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Final flush + shutdown. Registered once via `register_shutdown_function`
     * so single-shot mode (no persistent worker loop, no per-request reset
     * closure) still exports its one request's telemetry before the process
     * exits, and worker mode gets a last-chance flush when the worker itself
     * terminates.
     */
    public static function shutdown(): void
    {
        self::flushAfterRequest();
        try {
            TraceRegistry::tracerProvider()?->shutdown();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] tracer provider shutdown failed: ' . $e::class . ': ' . $e->getMessage());
        }
        try {
            TraceRegistry::meterProvider()?->shutdown();
        } catch (\Throwable $e) {
            Log::for(self::class)->error('[TelemetryBootstrap] meter provider shutdown failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Reset all bootstrap + registry state. For test isolation (simulating a
     * fresh worker); not used on the request path. Does not (and cannot)
     * un-register a previously scheduled `register_shutdown_function`
     * callback — that callback re-reads {@see TraceRegistry} when the process
     * actually exits, so it is a safe no-op once the provider has been
     * cleared by this call.
     */
    public static function reset(): void
    {
        self::$configured = false;
        self::$inMemorySpanExporter = null;
        self::$inMemoryMetricExporter = null;
        TraceRegistry::reset();
    }

    /** The in-memory span exporter, when `telemetry.exporter = none` was used. For tests. */
    public static function inMemorySpanExporter(): ?\OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter
    {
        return self::$inMemorySpanExporter;
    }

    /** The in-memory metric exporter, when `telemetry.exporter = none` was used. For tests. */
    public static function inMemoryMetricExporter(): ?\OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter
    {
        return self::$inMemoryMetricExporter;
    }

    // --- construction --------------------------------------------------------

    private static function buildResource(): \OpenTelemetry\SDK\Resource\ResourceInfo
    {
        $serviceName = Config::getString('telemetry.service.name', '') ?: Config::getString('core.app_name', 'quiote-app');
        $attributes = [\OpenTelemetry\SemConv\ResourceAttributes::SERVICE_NAME => $serviceName];

        $namespace = Config::getString('telemetry.service.namespace', '');
        if ($namespace) {
            $attributes[\OpenTelemetry\SemConv\ResourceAttributes::SERVICE_NAMESPACE] = $namespace;
        }

        foreach (Config::getArray('telemetry.resource', []) as $key => $value) {
            $attributes[$key] = $value;
        }

        return \OpenTelemetry\SDK\Resource\ResourceInfoFactory::defaultResource()->merge(
            \OpenTelemetry\SDK\Resource\ResourceInfo::create(
                \OpenTelemetry\SDK\Common\Attribute\Attributes::create($attributes)
            )
        );
    }

    private static function isWorkerMode(): bool
    {
        return FrankenPhpWorkerAdapter::isSupported();
    }

    private static function buildTracerProvider(\OpenTelemetry\SDK\Resource\ResourceInfo $resource): \OpenTelemetry\SDK\Trace\TracerProviderInterface
    {
        $exporter = self::buildSpanExporter();
        $mode = Config::getString('telemetry.export.mode', self::isWorkerMode() ? 'batch' : 'simple');

        $processor = $mode === 'simple'
            ? new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter)
            : (new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder($exporter))->build();

        return (new \OpenTelemetry\SDK\Trace\TracerProviderBuilder())
            ->addSpanProcessor($processor)
            ->setResource($resource)
            ->setSampler(self::buildSampler())
            ->build();
    }

    /**
     * Head-based sampling. Metrics are never sampled — this only ever affects
     * the TracerProvider.
     */
    private static function buildSampler(): \OpenTelemetry\SDK\Trace\SamplerInterface
    {
        $strategy = strtolower(Config::getString('telemetry.sampling.strategy', 'parentbased_traceidratio'));
        $ratio = Config::getFloat('telemetry.sampling.ratio', 0.1);

        $base = match ($strategy) {
            'always_on' => new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler(),
            'always_off' => new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler(),
            'parentbased_traceidratio' => new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
                new \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler($ratio)
            ),
            default => self::fallbackSampler($strategy, $ratio),
        };

        return new ForceSampleSampler($base);
    }

    private static function fallbackSampler(string $strategy, float $ratio): \OpenTelemetry\SDK\Trace\SamplerInterface
    {
        Log::for(self::class)->warning('[TelemetryBootstrap] unknown telemetry.sampling.strategy "' . $strategy . '", falling back to "parentbased_traceidratio".');
        return new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
            new \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler($ratio)
        );
    }

    private static function buildMeterProvider(\OpenTelemetry\SDK\Resource\ResourceInfo $resource): \OpenTelemetry\SDK\Metrics\MeterProviderInterface
    {
        $reader = new \OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader(self::buildMetricExporter());

        return (new \OpenTelemetry\SDK\Metrics\MeterProviderBuilder())
            ->addReader($reader)
            ->setResource($resource)
            ->build();
    }

    private static function buildSpanExporter(): \OpenTelemetry\SDK\Trace\SpanExporterInterface
    {
        $exporter = strtolower(Config::getString('telemetry.exporter', 'otlp'));

        return match ($exporter) {
            'none' => self::$inMemorySpanExporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter(),
            'console' => (new \OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory())->create(),
            'otlp' => self::buildOtlpSpanExporter(),
            default => self::fallbackSpanExporter($exporter),
        };
    }

    private static function buildMetricExporter(): \OpenTelemetry\SDK\Metrics\MetricExporterInterface
    {
        $exporter = strtolower(Config::getString('telemetry.exporter', 'otlp'));

        return match ($exporter) {
            'none' => self::$inMemoryMetricExporter = new \OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter(),
            'console' => (new \OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporterFactory())->create(),
            'otlp' => self::buildOtlpMetricExporter(),
            default => self::fallbackMetricExporter($exporter),
        };
    }

    private static function buildOtlpSpanExporter(): \OpenTelemetry\SDK\Trace\SpanExporterInterface
    {
        self::applyOtlpEnv();
        return (new \OpenTelemetry\Contrib\Otlp\SpanExporterFactory(self::otlpTransportFactory()))->create();
    }

    private static function buildOtlpMetricExporter(): \OpenTelemetry\SDK\Metrics\MetricExporterInterface
    {
        self::applyOtlpEnv();
        return (new \OpenTelemetry\Contrib\Otlp\MetricExporterFactory(self::otlpTransportFactory()))->create();
    }

    /**
     * The transport factory the OTLP exporter factories use to send data.
     *
     * The SDK's exporter factories otherwise resolve a PSR-18 client via
     * `php-http/discovery`, which fails hard when no PSR-18 implementation is
     * installed — the historical reason `telemetry.exporter = otlp` silently
     * degraded to disabled unless the app also pulled in a client package. Now
     * that Quiote ships its own zero-dependency PSR-18 client
     * ({@see \Quiote\Http\Client\CurlTransport}), we hand it to the SDK
     * explicitly so OTLP export works out of the box with no extra Composer
     * package (this is the egress seam the HTTP client abstraction unblocked).
     * The SDK factory still owns
     * endpoint resolution (appending `/v1/traces` etc.), protocol → content
     * type, headers, compression, and retries — we only supply the client.
     *
     * If ext-curl is somehow unavailable, we fall back to `null` so the SDK's
     * own discovery runs (and telemetry degrades to disabled if that finds
     * nothing, exactly as before) rather than fataling here.
     */
    private static function otlpTransportFactory(): ?\OpenTelemetry\SDK\Common\Export\TransportFactoryInterface
    {
        if (!\function_exists('curl_init')) {
            return null;
        }
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        return new \OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory(
            new \Quiote\Http\Client\CurlTransport($psr17, $psr17),
            $psr17,
            $psr17,
        );
    }

    /**
     * Bridges telemetry.otlp.* config into the OTEL_EXPORTER_OTLP_* env vars
     * the OTLP exporter factories read internally (via the SDK's own
     * `Configuration` singleton) — simpler and far less error-prone than
     * hand-building a Transport by reaching into `Registry` internals
     * ourselves. Process-wide, but only ever set when telemetry is enabled
     * with the otlp exporter, and the values don't change per-request.
     */
    private static function applyOtlpEnv(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=' . Config::getString('telemetry.otlp.endpoint', 'http://localhost:4318'));
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=' . Config::getString('telemetry.otlp.protocol', 'http/protobuf'));

        $headers = Config::getArray('telemetry.otlp.headers', []);
        if ($headers !== []) {
            $encoded = [];
            foreach ($headers as $key => $value) {
                $encoded[] = $key . '=' . $value;
            }
            putenv('OTEL_EXPORTER_OTLP_HEADERS=' . implode(',', $encoded));
        }
    }

    /**
     * telemetry.exporter has an unrecognized value: rather than fail the whole
     * provider (and thus disable telemetry entirely over a typo), fall back to
     * the safe local in-memory exporter and log why.
     */
    private static function fallbackSpanExporter(string $exporter): \OpenTelemetry\SDK\Trace\SpanExporterInterface
    {
        Log::for(self::class)->warning('[TelemetryBootstrap] unknown telemetry.exporter "' . $exporter . '", falling back to "none".');
        return self::$inMemorySpanExporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
    }

    private static function fallbackMetricExporter(string $exporter): \OpenTelemetry\SDK\Metrics\MetricExporterInterface
    {
        Log::for(self::class)->warning('[TelemetryBootstrap] unknown telemetry.exporter "' . $exporter . '", falling back to "none".');
        return self::$inMemoryMetricExporter = new \OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter();
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            self::shutdown();
        });
    }
}
