<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigRepository;
use Quiote\Telemetry\TelemetryConfig;

/**
 * The settings resolution, exercised against a repository of its own rather than by mutating
 * process-wide configuration and hoping to restore it.
 */
final class TelemetryConfigTest extends TestCase
{
    private ?ConfigRepository $original = null;
    private bool $swapped = false;

    protected function tearDown(): void
    {
        if ($this->swapped) {
            Config::useRepository($this->original);
            $this->original = null;
            $this->swapped = false;
        }
    }

    /**
     * Install a configuration of its own and resolve the settings from it.
     *
     * The original repository is captured on the first swap only: a test that calls this more than
     * once would otherwise "restore" one of its own replacements and leak an empty configuration
     * into every later test in the process.
     *
     * @param array<string, mixed> $settings
     */
    private function withSettings(array $settings): TelemetryConfig
    {
        $previous = Config::useRepository(new ConfigRepository($settings));
        if (!$this->swapped) {
            $this->original = $previous;
            $this->swapped = true;
        }

        return TelemetryConfig::fromConfig();
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = $this->withSettings([]);

        $this->assertSame('quiote-app', $config->serviceName);
        $this->assertSame('', $config->serviceNamespace);
        $this->assertSame([], $config->resourceAttributes);
        $this->assertSame('otlp', $config->exporter);
        $this->assertSame('parentbased_traceidratio', $config->samplingStrategy);
        $this->assertSame(0.1, $config->samplingRatio);
        $this->assertSame('http://localhost:4318', $config->otlpEndpoint);
        $this->assertSame('http/protobuf', $config->otlpProtocol);
        $this->assertSame([], $config->otlpHeaders);
    }

    public function testServiceNameFallsBackToTheApplicationName(): void
    {
        $this->assertSame('my-app', $this->withSettings(['core.app_name' => 'my-app'])->serviceName);
    }

    public function testExplicitServiceNameWinsOverTheApplicationName(): void
    {
        $config = $this->withSettings([
            'core.app_name' => 'my-app',
            'telemetry.service.name' => 'orders-api',
        ]);

        $this->assertSame('orders-api', $config->serviceName);
    }

    public function testExporterNameIsLowercased(): void
    {
        $this->assertSame('console', $this->withSettings(['telemetry.exporter' => 'CONSOLE'])->exporter);
    }

    public function testSamplingStrategyIsLowercased(): void
    {
        $this->assertSame(
            'always_on',
            $this->withSettings(['telemetry.sampling.strategy' => 'ALWAYS_ON'])->samplingStrategy
        );
    }

    public function testResourceAttributesAreCarriedThrough(): void
    {
        $config = $this->withSettings(['telemetry.resource' => ['deployment.environment' => 'staging']]);

        $this->assertSame(['deployment.environment' => 'staging'], $config->resourceAttributes);
    }

    public function testOtlpHeadersAreStringified(): void
    {
        $config = $this->withSettings([
            'telemetry.otlp.headers' => [
                'authorization' => 'Bearer t',
                'x-count' => 7,
                'x-flag' => true,
                'x-off' => false,
            ],
        ]);

        $this->assertSame([
            'authorization' => 'Bearer t',
            'x-count' => '7',
            'x-flag' => '1',
            'x-off' => '0',
        ], $config->otlpHeaders);
    }

    /**
     * A header can only carry a scalar, so a nested array is a configuration mistake that is
     * dropped rather than turned into the string "Array" in an outgoing header.
     */
    public function testNonScalarOtlpHeadersAreDropped(): void
    {
        $config = $this->withSettings([
            'telemetry.otlp.headers' => ['good' => 'yes', 'bad' => ['nested']],
        ]);

        $this->assertSame(['good' => 'yes'], $config->otlpHeaders);
    }

    public function testExportModeCanBeConfiguredExplicitly(): void
    {
        $this->assertSame('batch', $this->withSettings(['telemetry.export.mode' => 'batch'])->exportMode);
        $this->assertSame('simple', $this->withSettings(['telemetry.export.mode' => 'simple'])->exportMode);
    }

    /**
     * Batching only pays off when the process outlives the request, so the default follows the
     * runtime rather than a fixed value.
     */
    public function testExportModeDefaultsByRuntime(): void
    {
        $expected = \Quiote\Runtime\Worker\WorkerRuntimeInfo::isPersistent() ? 'batch' : 'simple';

        $this->assertSame($expected, $this->withSettings([])->exportMode);
    }
}
