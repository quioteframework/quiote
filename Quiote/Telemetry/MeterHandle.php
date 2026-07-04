<?php

namespace Quiote\Telemetry;

/**
 * Records metric instruments (histograms, counters, gauges). Unlike spans,
 * metric recordings are never sampled — every call here is meant to always
 * count toward the aggregate.
 */
interface MeterHandle
{
    /** @param array<string,mixed> $attributes */
    public function recordHistogram(string $name, float $value, array $attributes = []): void;

    /** @param array<string,mixed> $attributes */
    public function addCounter(string $name, int|float $increment = 1, array $attributes = []): void;

    /** @param array<string,mixed> $attributes */
    public function recordGauge(string $name, float $value, array $attributes = []): void;
}
