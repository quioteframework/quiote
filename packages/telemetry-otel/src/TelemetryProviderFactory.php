<?php

declare(strict_types=1);

namespace Quiote\Telemetry;

use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Quiote\Logging\Log;

/**
 * Assembles the TracerProvider and MeterProvider: the resource describing this service, the
 * sampler, the span processor, and the metric reader.
 *
 * Takes its exporters from a {@see TelemetryExporterFactory} rather than building them, so which
 * exporter is in use and how the providers are wired around it stay separable -- a caller can
 * assemble providers over an in-memory exporter without any OTLP configuration existing.
 *
 * @since      3.2.0
 */
final readonly class TelemetryProviderFactory
{
    public function __construct(
        private TelemetryConfig $config,
        private TelemetryExporterFactory $exporters,
    ) {
    }

    /**
     * The resource every span and metric is attributed to: service name, optional namespace, and
     * whatever `telemetry.resource` adds, merged over the SDK's own detected defaults.
     */
    public function resource(): ResourceInfo
    {
        $attributes = [
            \OpenTelemetry\SemConv\ResourceAttributes::SERVICE_NAME => $this->config->serviceName,
        ];

        if ($this->config->serviceNamespace !== '') {
            $attributes[\OpenTelemetry\SemConv\ResourceAttributes::SERVICE_NAMESPACE] = $this->config->serviceNamespace;
        }

        foreach ($this->config->resourceAttributes as $key => $value) {
            $attributes[$key] = $value;
        }

        return \OpenTelemetry\SDK\Resource\ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(
                \OpenTelemetry\SDK\Common\Attribute\Attributes::create($attributes)
            )
        );
    }

    /**
     * Builds a TracerProvider over $resource and the configured sampler.
     *
     * Spans go through a SimpleSpanProcessor when `telemetry.export_mode` is
     * `simple` (each span exported as it ends, which tests rely on) and a
     * BatchSpanProcessor otherwise.
     */
    public function tracerProvider(ResourceInfo $resource): TracerProviderInterface
    {
        $exporter = $this->exporters->spanExporter();

        $processor = $this->config->exportMode === 'simple'
            ? new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter)
            : (new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder($exporter))->build();

        return (new \OpenTelemetry\SDK\Trace\TracerProviderBuilder())
            ->addSpanProcessor($processor)
            ->setResource($resource)
            ->setSampler($this->sampler())
            ->build();
    }

    /**
     * Builds a MeterProvider over $resource, reading through an
     * ExportingReader wrapped around the configured metric exporter. Sampling
     * does not apply to metrics.
     */
    public function meterProvider(ResourceInfo $resource): MeterProviderInterface
    {
        $reader = new \OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader($this->exporters->metricExporter());

        return (new \OpenTelemetry\SDK\Metrics\MeterProviderBuilder())
            ->addReader($reader)
            ->setResource($resource)
            ->build();
    }

    /**
     * Head-based sampling, wrapped so a span can force itself to be recorded. Metrics are never
     * sampled -- this only affects the TracerProvider.
     */
    public function sampler(): SamplerInterface
    {
        $ratio = $this->config->samplingRatio;

        $base = match ($this->config->samplingStrategy) {
            'always_on' => new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler(),
            'always_off' => new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler(),
            'parentbased_traceidratio' => self::ratioSampler($ratio),
            default => $this->fallbackSampler($ratio),
        };

        return new ForceSampleSampler($base);
    }

    private function fallbackSampler(float $ratio): SamplerInterface
    {
        Log::for($this)->warning(
            '[TelemetryProviderFactory] unknown telemetry.sampling.strategy "'
            . $this->config->samplingStrategy . '", falling back to "parentbased_traceidratio".'
        );

        return self::ratioSampler($ratio);
    }

    private static function ratioSampler(float $ratio): SamplerInterface
    {
        return new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
            new \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler($ratio)
        );
    }
}
