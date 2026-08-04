<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\ForceSampleSampler;
use Quiote\Telemetry\TelemetryConfig;
use Quiote\Telemetry\TelemetryExporterFactory;
use Quiote\Telemetry\TelemetryProviderFactory;

/**
 * Provider assembly, exercised over the in-memory exporter -- no OTLP configuration, no process
 * state, no bootstrap. This is what the static bootstrap module could not be asked in isolation.
 */
final class TelemetryProviderFactoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $resourceAttributes
     */
    private function factory(
        string $serviceName = 'orders-api',
        string $serviceNamespace = '',
        array $resourceAttributes = [],
        string $exportMode = 'simple',
        string $samplingStrategy = 'parentbased_traceidratio',
        float $samplingRatio = 0.1,
    ): TelemetryProviderFactory {
        $config = new TelemetryConfig(
            serviceName: $serviceName,
            serviceNamespace: $serviceNamespace,
            resourceAttributes: $resourceAttributes,
            exporter: 'none',
            exportMode: $exportMode,
            samplingStrategy: $samplingStrategy,
            samplingRatio: $samplingRatio,
            otlpEndpoint: 'http://localhost:4318',
            otlpProtocol: 'http/protobuf',
            otlpHeaders: [],
        );

        return new TelemetryProviderFactory($config, new TelemetryExporterFactory($config));
    }

    public function testResourceCarriesTheServiceName(): void
    {
        $resource = $this->factory()->resource();

        $this->assertSame('orders-api', $resource->getAttributes()->get('service.name'));
    }

    public function testResourceOmitsAnEmptyNamespace(): void
    {
        $this->assertNull($this->factory()->resource()->getAttributes()->get('service.namespace'));
    }

    public function testResourceCarriesTheNamespaceWhenSet(): void
    {
        $resource = $this->factory(serviceNamespace: 'retail')->resource();

        $this->assertSame('retail', $resource->getAttributes()->get('service.namespace'));
    }

    public function testResourceCarriesExtraAttributes(): void
    {
        $resource = $this->factory(resourceAttributes: ['deployment.environment' => 'staging'])->resource();

        $this->assertSame('staging', $resource->getAttributes()->get('deployment.environment'));
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function samplerProvider(): array
    {
        return [
            'always_on' => ['always_on', AlwaysOnSampler::class],
            'always_off' => ['always_off', AlwaysOffSampler::class],
            'ratio' => ['parentbased_traceidratio', ParentBased::class],
            'unknown falls back to ratio' => ['nonsense', ParentBased::class],
        ];
    }

    /**
     * @param class-string $expectedInner
     */
    #[PHPUnit\Framework\Attributes\DataProvider('samplerProvider')]
    public function testSamplerStrategy(string $strategy, string $expectedInner): void
    {
        $sampler = $this->factory(samplingStrategy: $strategy)->sampler();

        // Always wrapped, so a span can force itself to be recorded regardless of the strategy.
        $this->assertInstanceOf(ForceSampleSampler::class, $sampler);
        $this->assertStringContainsString(
            (new ReflectionClass($expectedInner))->getShortName(),
            $sampler->getDescription()
        );
    }

    public function testTracerProviderIsBuiltForBothExportModes(): void
    {
        foreach (['simple', 'batch'] as $mode) {
            $factory = $this->factory(exportMode: $mode);

            $provider = $factory->tracerProvider($factory->resource());

            $this->assertInstanceOf(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class, $provider);
            $provider->shutdown();
        }
    }

    public function testMeterProviderIsBuilt(): void
    {
        $factory = $this->factory();

        $provider = $factory->meterProvider($factory->resource());

        $this->assertInstanceOf(\OpenTelemetry\SDK\Metrics\MeterProviderInterface::class, $provider);
        $provider->shutdown();
    }

    /**
     * A span recorded through the assembled provider reaches the exporter the factory was given.
     */
    public function testSpansReachTheExporter(): void
    {
        $config = new TelemetryConfig(
            serviceName: 'orders-api',
            serviceNamespace: '',
            resourceAttributes: [],
            exporter: 'none',
            exportMode: 'simple',
            samplingStrategy: 'always_on',
            samplingRatio: 1.0,
            otlpEndpoint: 'http://localhost:4318',
            otlpProtocol: 'http/protobuf',
            otlpHeaders: [],
        );
        $exporters = new TelemetryExporterFactory($config);
        $providers = new TelemetryProviderFactory($config, $exporters);

        $provider = $providers->tracerProvider($providers->resource());
        $provider->getTracer('test')->spanBuilder('probe')->startSpan()->end();
        $provider->forceFlush();

        $inMemory = $exporters->inMemorySpanExporter();
        $this->assertNotNull($inMemory);
        $names = array_map(static fn($span): string => $span->getName(), $inMemory->getSpans());
        $this->assertContains('probe', $names);

        $provider->shutdown();
    }
}
