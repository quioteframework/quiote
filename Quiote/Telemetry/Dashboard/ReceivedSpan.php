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

    public function durationNanos(): int
    {
        return max(0, $this->endTimeUnixNano - $this->startTimeUnixNano);
    }

    public function durationMillis(): float
    {
        return $this->durationNanos() / 1_000_000.0;
    }

    /** OTel `Status.StatusCode`: 0 = Unset, 1 = Ok, 2 = Error. */
    public function isError(): bool
    {
        return $this->statusCode === 2;
    }

    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }

    public function serviceName(): ?string
    {
        $value = $this->resourceAttributes['service.name'] ?? null;
        return is_string($value) ? $value : null;
    }
}
