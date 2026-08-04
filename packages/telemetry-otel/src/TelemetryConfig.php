<?php

declare(strict_types=1);

namespace Quiote\Telemetry;

use Quiote\Config\Config;
use Quiote\Logging\Log;
use Quiote\Runtime\Worker\WorkerRuntimeInfo;

/**
 * The `telemetry.*` settings, read once and resolved into concrete values.
 *
 * Every decision that depends on configuration or on the environment is made here, so the
 * factories that build exporters and providers take plain values and can be exercised without
 * touching process-wide config. That is the whole point of the separation: the interesting part of
 * telemetry setup is which exporter and sampler you end up with, and testing that should not
 * require mutating globals.
 *
 * @since      3.2.0
 */
final readonly class TelemetryConfig
{
    /**
     * @param      array<string, mixed> $resourceAttributes Extra resource attributes.
     * @param      array<string, string> $otlpHeaders Header name => value, already stringified.
     */
    public function __construct(
        public string $serviceName,
        public string $serviceNamespace,
        public array $resourceAttributes,
        public string $exporter,
        public string $exportMode,
        public string $samplingStrategy,
        public float $samplingRatio,
        public string $otlpEndpoint,
        public string $otlpProtocol,
        public array $otlpHeaders,
    ) {
    }

    /**
     * Read the settings, resolving the defaults that depend on the environment.
     *
     * The export mode defaults by runtime: batching only pays off when the process outlives the
     * request. This is read during boot, before the Kernel has selected a runtime, so
     * WorkerRuntimeInfo answers from auto-detection rather than from an installed runtime -- which
     * is correct, because plugins (including any contributing a runtime) have already registered.
     */
    public static function fromConfig(): self
    {
        $serviceName = Config::getString('telemetry.service.name', '')
            ?: Config::getString('core.app_name', 'quiote-app');

        return new self(
            serviceName: $serviceName,
            serviceNamespace: Config::getString('telemetry.service.namespace', ''),
            resourceAttributes: Config::getArray('telemetry.resource', []),
            exporter: strtolower(Config::getString('telemetry.exporter', 'otlp')),
            exportMode: Config::getString('telemetry.export.mode', WorkerRuntimeInfo::isPersistent() ? 'batch' : 'simple'),
            samplingStrategy: strtolower(Config::getString('telemetry.sampling.strategy', 'parentbased_traceidratio')),
            samplingRatio: Config::getFloat('telemetry.sampling.ratio', 0.1),
            otlpEndpoint: Config::getString('telemetry.otlp.endpoint', 'http://localhost:4318'),
            otlpProtocol: Config::getString('telemetry.otlp.protocol', 'http/protobuf'),
            otlpHeaders: self::readOtlpHeaders(),
        );
    }

    /**
     * OTLP headers, with non-scalar entries dropped.
     *
     * A header can only carry a scalar, so a nested array here is a configuration mistake worth
     * naming rather than turning into the string "Array" in an outgoing header.
     *
     * @return     array<string, string>
     */
    private static function readOtlpHeaders(): array
    {
        $headers = [];
        foreach (Config::getArray('telemetry.otlp.headers', []) as $key => $value) {
            if (!is_scalar($value)) {
                Log::for(self::class)->warning(
                    '[TelemetryConfig] ignoring non-scalar telemetry.otlp.headers entry "' . $key . '".'
                );
                continue;
            }
            $headers[(string) $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $headers;
    }
}
