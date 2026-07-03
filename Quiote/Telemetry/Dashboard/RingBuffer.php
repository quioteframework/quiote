<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * A fixed-window, per-second time series. Samples are recorded into the
 * bucket for the second they arrived in; buckets older than the window are
 * pruned on every {@see record()} call, so memory is bounded by
 * `$windowSeconds` regardless of how long the dashboard runs or how much
 * traffic it observes -- the same "no unbounded retention across a long run"
 * discipline `docs/OPENTELEMETRY_PLAN.md` Phase 2 holds for span/metric
 * providers.
 *
 * Deliberately takes the current second as a parameter rather than reading
 * the clock itself, so it is fully deterministic and unit-testable without
 * real wall-clock time.
 */
final class RingBuffer
{
    /** @var array<int,float[]> second => raw values recorded in that second */
    private array $buckets = [];

    public function __construct(private readonly int $windowSeconds)
    {
    }

    public function record(int $second, float $value): void
    {
        $this->buckets[$second][] = $value;
        $this->prune($second);
    }

    /**
     * @param 'sum'|'avg'|'max'|'last'|'count' $aggregate
     * @return array<int,float> second => aggregated value, in chronological
     *         order, with every second in the window present (missing
     *         seconds get $default)
     */
    public function series(int $nowSecond, string $aggregate = 'sum', float $default = 0.0): array
    {
        $series = [];
        $start = $nowSecond - $this->windowSeconds + 1;

        for ($second = $start; $second <= $nowSecond; $second++) {
            $values = $this->buckets[$second] ?? [];
            $series[$second] = $values === [] ? $default : $this->aggregate($values, $aggregate);
        }

        return $series;
    }

    /** @param float[] $values */
    private function aggregate(array $values, string $aggregate): float
    {
        return match ($aggregate) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'max' => max($values),
            'last' => $values[array_key_last($values)],
            'count' => (float) count($values),
            default => throw new \InvalidArgumentException(sprintf('Unknown aggregate "%s".', $aggregate)),
        };
    }

    private function prune(int $currentSecond): void
    {
        $cutoff = $currentSecond - $this->windowSeconds;
        foreach (array_keys($this->buckets) as $second) {
            if ($second < $cutoff) {
                unset($this->buckets[$second]);
            }
        }
    }
}
