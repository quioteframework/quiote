<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\NotFoundException;
use Quiote\Telemetry\TelemetryBootstrap;
use Quiote\Telemetry\TraceRegistry;

/**
 * Phase 2: DI container registration of the OpenTelemetry provider aliases
 * (docs/OPENTELEMETRY_PLAN.md). Run in separate processes because Context/
 * Config carry process-global state that's otherwise easy to leak between
 * tests (same isolation MiddlewarePipelineTest uses for the same reason).
 */
#[RunTestsInSeparateProcesses]
class TelemetryContainerRegistrationTest extends TestCase
{
    public function testContainerResolvesTheSameProviderInstanceTraceRegistryHolds(): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        TelemetryBootstrap::configureFromConfig();

        $container = Context::getInstance()->getContainer();

        $tracerProvider = $container->get(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class);
        $this->assertSame(TraceRegistry::tracerProvider(), $tracerProvider);

        // The API-level interface alias resolves to the same SDK instance.
        $viaApiAlias = $container->get(\OpenTelemetry\API\Trace\TracerProviderInterface::class);
        $this->assertSame($tracerProvider, $viaApiAlias);

        $meterProvider = $container->get(\OpenTelemetry\SDK\Metrics\MeterProviderInterface::class);
        $this->assertSame(TraceRegistry::meterProvider(), $meterProvider);
    }

    public function testContainerHasNoTelemetryServicesWhenDisabled(): void
    {
        // telemetry.enabled defaults to false in this process; nothing configured.
        $container = Context::getInstance()->getContainer();

        $this->expectException(NotFoundException::class);
        $container->get(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class);
    }

    public function testContainerHasNoTelemetryServicesWhenEnabledButSdkConstructionFailed(): void
    {
        // Enabled, but the otlp exporter can't be built — here forced with an
        // invalid endpoint URL, which the transport factory rejects. (OTLP no
        // longer fails merely for want of a PSR-18 client: Quiote ships its own
        // CurlTransport now — see TelemetryBootstrapTest.) TelemetryBootstrap
        // must still fall back to disabled on a genuine construction failure,
        // and the container registration must follow suit rather than register
        // a service backed by a provider that doesn't exist.
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'otlp', true);
        Config::set('telemetry.otlp.endpoint', 'not a valid url', true);
        $this->assertFalse(TelemetryBootstrap::configureFromConfig());

        $container = Context::getInstance()->getContainer();

        $this->expectException(NotFoundException::class);
        $container->get(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class);
    }
}
