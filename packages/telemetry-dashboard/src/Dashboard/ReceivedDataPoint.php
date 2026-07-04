<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * A single data point within a {@see ReceivedMetric}. `$value` is the gauge
 * reading / sum total for gauge and sum metrics, or the histogram's `sum`
 * for histogram metrics -- `$count` is only meaningful (non-null) for
 * histograms, where `$value / $count` is the mean.
 */
final class ReceivedDataPoint
{
    /** @param array<string,mixed> $attributes */
    public function __construct(
        public readonly array $attributes,
        public readonly float $value,
        public readonly ?int $count,
        public readonly int $timeUnixNano,
    ) {
    }

    public function mean(): ?float
    {
        if ($this->count === null || $this->count === 0) {
            return null;
        }
        return $this->value / $this->count;
    }
}
