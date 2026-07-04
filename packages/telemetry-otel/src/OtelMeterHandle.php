<?php

namespace Quiote\Telemetry;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use Quiote\Logging\Level;
use Quiote\Logging\Log;

/**
 * Real {@see MeterHandle}, wrapping an OpenTelemetry {@see MeterInterface}.
 * Instruments are created once per name and cached for the worker's lifetime
 * (recreating the Counter/Histogram/Gauge object on every recording call
 * would be wasteful and risks metadata — unit/description — diverging between
 * calls for the same instrument name).
 *
 * Every recording is wrapped so a call site can never crash the request; see
 * {@see OtelSpanHandle} for the same rationale.
 */
final class OtelMeterHandle implements MeterHandle
{
    /** @var array<string,HistogramInterface> */
    private array $histograms = [];

    /** @var array<string,CounterInterface> */
    private array $counters = [];

    /** @var array<string,GaugeInterface> */
    private array $gauges = [];

    public function __construct(private readonly MeterInterface $meter)
    {
    }

    public function recordHistogram(string $name, float $value, array $attributes = []): void
    {
        $this->safely($name, function () use ($name, $value, $attributes): void {
            $histogram = $this->histograms[$name] ??= $this->meter->createHistogram($name);
            $histogram->record($value, $attributes);
        });
    }

    public function addCounter(string $name, int|float $increment = 1, array $attributes = []): void
    {
        $this->safely($name, function () use ($name, $increment, $attributes): void {
            $counter = $this->counters[$name] ??= $this->meter->createCounter($name);
            $counter->add($increment, $attributes);
        });
    }

    public function recordGauge(string $name, float $value, array $attributes = []): void
    {
        $this->safely($name, function () use ($name, $value, $attributes): void {
            $gauge = $this->gauges[$name] ??= $this->meter->createGauge($name);
            $gauge->record($value, $attributes);
        });
    }

    private function safely(string $instrumentName, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (Log::for(self::class)->isEnabled(Level::Debug)) {
                Log::for(self::class)->debug('[OtelMeterHandle] recording "' . $instrumentName . '" failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }
}
