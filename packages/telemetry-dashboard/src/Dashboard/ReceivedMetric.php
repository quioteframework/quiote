<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * A metric decoded from an OTLP `ExportMetricsServiceRequest` by
 * {@see OtlpDecoder}. `$type` is one of `'gauge'`, `'sum'`, `'histogram'` --
 * the only three shapes {@see \Quiote\Middleware\TelemetryMiddleware} emits
 * (see docs/OPENTELEMETRY_PLAN.md, Phase 3). Other OTLP metric types
 * (exponential histogram, summary) are skipped by the decoder rather than
 * represented here.
 */
final class ReceivedMetric
{
    /**
     * @param 'gauge'|'sum'|'histogram' $type
     * @param ReceivedDataPoint[] $dataPoints
     * @param array<string,mixed> $resourceAttributes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $dataPoints,
        public readonly array $resourceAttributes,
    ) {
    }
}
