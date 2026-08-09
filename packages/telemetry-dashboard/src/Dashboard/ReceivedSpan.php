<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * A span decoded from an OTLP `ExportTraceServiceRequest` by
 * {@see OtlpDecoder}, flattened into plain PHP values so nothing downstream
 * (DashboardState, DashboardView, tests) needs to touch protobuf types.
 */
final class ReceivedSpan
{
    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $resourceAttributes
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly int $kind,
        public readonly int $startTimeUnixNano,
        public readonly int $endTimeUnixNano,
        public readonly int $statusCode,
        public readonly string $statusMessage,
        public readonly array $attributes,
        public readonly array $resourceAttributes,
    ) {
    }

    /**
     * The span's wall-clock duration in nanoseconds, clamped at zero so an
     * end timestamp that precedes the start one never yields a negative
     * duration.
     */
    public function durationNanos(): int
    {
        return max(0, $this->endTimeUnixNano - $this->startTimeUnixNano);
    }

    /** The span's duration in milliseconds, derived from {@see durationNanos()}. */
    public function durationMillis(): float
    {
        return $this->durationNanos() / 1_000_000.0;
    }

    /** OTel `Status.StatusCode`: 0 = Unset, 1 = Ok, 2 = Error. */
    public function isError(): bool
    {
        return $this->statusCode === 2;
    }

    /** Whether this is the trace's root span, i.e. it carries no parent span ID. */
    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }

    /**
     * The `service.name` resource attribute, or null when the exporter sent
     * no such attribute or sent a non-string value for it.
     */
    public function serviceName(): ?string
    {
        $value = $this->resourceAttributes['service.name'] ?? null;
        return is_string($value) ? $value : null;
    }
}
