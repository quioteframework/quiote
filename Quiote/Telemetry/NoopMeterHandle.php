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

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function recordHistogram(string $name, float $value, array $attributes = []): void
    {
    }

    public function addCounter(string $name, int|float $increment = 1, array $attributes = []): void
    {
    }

    public function recordGauge(string $name, float $value, array $attributes = []): void
    {
    }
}
