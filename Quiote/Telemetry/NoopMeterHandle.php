<?php

namespace Quiote\Telemetry;

/**
 * The disabled-state {@see MeterHandle}: every recording is a safe no-op. A
 * single shared instance is reused ({@see instance()}), same rationale as
 * {@see NoopSpanHandle}.
 */
final class NoopMeterHandle implements MeterHandle
{
    private static ?self $instance = null;

    /**
     * The shared no-op meter handle, created on first call and reused for the
     * rest of the process. Safe to hand out freely: it is stateless and every
     * recording on it is discarded.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Discards the histogram measurement. */
    public function recordHistogram(string $name, float $value, array $attributes = []): void
    {
    }

    /** Discards the counter increment. */
    public function addCounter(string $name, int|float $increment = 1, array $attributes = []): void
    {
    }

    /** Discards the gauge measurement. */
    public function recordGauge(string $name, float $value, array $attributes = []): void
    {
    }
}
