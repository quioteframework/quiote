<?php

declare(strict_types=1);

namespace Quiote\Telemetry;

use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Quiote\Logging\Log;

/**
 * Builds the span and metric exporters named by `telemetry.exporter`.
 *
 * One instance per configuration, holding the in-memory exporters it created so a test can read
 * back what was exported. An unrecognised exporter name falls back to the in-memory one rather
 * than failing the provider, because disabling telemetry entirely over a typo is worse than
 * exporting nowhere and saying so.
 *
 * @since      3.2.0
 */
final class TelemetryExporterFactory
{
    private ?InMemorySpanExporter $inMemorySpanExporter = null;
    private ?InMemoryMetricExporter $inMemoryMetricExporter = null;

    public function __construct(private readonly TelemetryConfig $config) {}

    /**
     * Builds the span exporter named by `telemetry.exporter`.
     *
     * `none` and any unrecognised name yield an in-memory exporter, which is
     * retained for {@see inMemorySpanExporter()}; an unrecognised name is also
     * logged at warning level. `otlp` bridges the OTLP settings into the
     * `OTEL_EXPORTER_OTLP_*` environment before delegating to the SDK factory.
     */
    public function spanExporter(): SpanExporterInterface
    {
        return match ($this->config->exporter) {
            'none' => $this->inMemorySpanExporter = new InMemorySpanExporter(),
            'console' => (new \OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory())->create(),
            'otlp' => $this->otlpSpanExporter(),
            default => $this->fallbackSpanExporter(),
        };
    }

    /**
     * Builds the metric exporter named by `telemetry.exporter`, on the same
     * terms as {@see spanExporter()}; the in-memory result is retained for
     * {@see inMemoryMetricExporter()}.
     */
    public function metricExporter(): MetricExporterInterface
    {
        return match ($this->config->exporter) {
            'none' => $this->inMemoryMetricExporter = new InMemoryMetricExporter(),
            'console' => (new \OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporterFactory())->create(),
            'otlp' => $this->otlpMetricExporter(),
            default => $this->fallbackMetricExporter(),
        };
    }

    /** The in-memory span exporter, when one was built. For tests. */
    public function inMemorySpanExporter(): ?InMemorySpanExporter
    {
        return $this->inMemorySpanExporter;
    }

    /** The in-memory metric exporter, when one was built. For tests. */
    public function inMemoryMetricExporter(): ?InMemoryMetricExporter
    {
        return $this->inMemoryMetricExporter;
    }

    private function otlpSpanExporter(): SpanExporterInterface
    {
        $this->applyOtlpEnv();

        return (new \OpenTelemetry\Contrib\Otlp\SpanExporterFactory($this->otlpTransportFactory()))->create();
    }

    private function otlpMetricExporter(): MetricExporterInterface
    {
        $this->applyOtlpEnv();

        return (new \OpenTelemetry\Contrib\Otlp\MetricExporterFactory($this->otlpTransportFactory()))->create();
    }

    /**
     * The transport the OTLP exporter factories send through.
     *
     * Left to the SDK's own `php-http/discovery` resolution, this fails hard when no PSR-18
     * implementation is installed -- the reason `telemetry.exporter = otlp` used to degrade to
     * disabled unless the application pulled in a client package. Quiote ships a zero-dependency
     * PSR-18 client, so it is handed over explicitly and OTLP export works with no extra Composer
     * package. The SDK factory still owns endpoint resolution, protocol-to-content-type mapping,
     * headers, compression and retries; only the client comes from here.
     *
     * Null when ext-curl is unavailable, which lets the SDK's discovery run instead of fataling.
     */
    private function otlpTransportFactory(): ?\OpenTelemetry\SDK\Common\Export\TransportFactoryInterface
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
     * Bridge the OTLP settings into the `OTEL_EXPORTER_OTLP_*` environment variables the exporter
     * factories read through the SDK's own configuration singleton -- far less error-prone than
     * hand-building a transport by reaching into the SDK's registry internals. Process-wide, but
     * only ever set when telemetry is enabled with the OTLP exporter, and the values do not change
     * per request.
     */
    private function applyOtlpEnv(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=' . $this->config->otlpEndpoint);
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=' . $this->config->otlpProtocol);

        if ($this->config->otlpHeaders !== []) {
            $encoded = [];
            foreach ($this->config->otlpHeaders as $key => $value) {
                $encoded[] = $key . '=' . $value;
            }
            putenv('OTEL_EXPORTER_OTLP_HEADERS=' . implode(',', $encoded));
        }
    }

    private function fallbackSpanExporter(): SpanExporterInterface
    {
        Log::for($this)->warning(
            '[TelemetryExporterFactory] unknown telemetry.exporter "' . $this->config->exporter
            . '", falling back to "none".'
        );

        return $this->inMemorySpanExporter = new InMemorySpanExporter();
    }

    private function fallbackMetricExporter(): MetricExporterInterface
    {
        Log::for($this)->warning(
            '[TelemetryExporterFactory] unknown telemetry.exporter "' . $this->config->exporter
            . '", falling back to "none".'
        );

        return $this->inMemoryMetricExporter = new InMemoryMetricExporter();
    }
}
