<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * The rolling in-memory store fed by {@see OtlpReceiver}'s decoded batches
 * and read by the render loop via {@see snapshot()}. Single-threaded (one
 * Revolt event loop drives both ingestion and rendering -- see
 * docs/TELEMETRY_DASHBOARD_PLAN.md, "The core idea"), so plain arrays with no
 * locking are correct here.
 *
 * **Divergence from the original plan sketch, worth calling out**: throughput,
 * latency (avg/p95/max), error rate, per-route stats, and the recent-request/
 * error feeds are all derived from **root spans**, not from
 * `http.server.request.count`/`http.server.request.duration` metrics as the
 * plan doc's "Metric extraction" section originally sketched. Once Phase 6 of
 * `docs/OPENTELEMETRY_PLAN.md` shipped, the root span itself already carries
 * `http.route`/`route_name`/`quiote.cache.hit`/`http.response.status_code`
 * attributes (see `RoutingMiddleware::process()` and
 * `TelemetryMiddleware::recordMeasurements()`) plus its own real duration and
 * OTel `Status` -- a strictly richer, per-request-precise source than
 * aggregated histogram buckets, and it means genuine percentiles (not
 * bucket-boundary estimates) are possible from a bounded reservoir of raw
 * samples. Metrics are still the only source for **CPU time and memory**,
 * which spans don't carry (see `TelemetryMiddleware`'s Phase 3 status notes on
 * why those live on the metrics side), and for worker RSS, an aggregate signal
 * with no meaningful per-span equivalent at all.
 */
final class DashboardState
{
    private const WINDOW_SECONDS = 120;
    private const LATENCY_SAMPLE_CAP = 2000;
    private const RECENT_FEED_CAP = 50;
    private const RECENT_ERRORS_CAP = 50;
    private const ERROR_STATUS_CODE = 2; // OTel Status.StatusCode: 0=Unset, 1=Ok, 2=Error

    private readonly RingBuffer $throughput;
    private readonly RingBuffer $latencyAvg;
    private readonly RingBuffer $cpuUser;
    private readonly RingBuffer $cpuSystem;
    private readonly RingBuffer $memoryPeak;
    private readonly RouteStats $routeStats;

    private int $totalRequests = 0;
    private int $totalErrors = 0;
    private float $workerRssBytes = 0.0;
    private ?int $startedAtSecond = null;

    /** @var float[] bounded reservoir used to compute avg/p95/max latency */
    private array $latencySamples = [];

    /** @var list<array{second:int,traceId:string,name:string,statusCode:int,durationMs:float,isError:bool,statusMessage:string}> */
    private array $recentSpans = [];

    /** @var list<array{second:int,traceId:string,name:string,statusCode:int,durationMs:float,isError:bool,statusMessage:string}> */
    private array $recentErrors = [];

    public function __construct()
    {
        $this->throughput = new RingBuffer(self::WINDOW_SECONDS);
        $this->latencyAvg = new RingBuffer(self::WINDOW_SECONDS);
        $this->cpuUser = new RingBuffer(self::WINDOW_SECONDS);
        $this->cpuSystem = new RingBuffer(self::WINDOW_SECONDS);
        $this->memoryPeak = new RingBuffer(self::WINDOW_SECONDS);
        $this->routeStats = new RouteStats();
    }

    /** @param ReceivedSpan[] $spans */
    public function ingestSpans(array $spans, int $second): void
    {
        foreach ($spans as $span) {
            if (!$span->isRoot()) {
                continue;
            }

            $this->startedAtSecond ??= $second;
            $this->totalRequests++;

            $durationMs = $span->durationMillis();
            $this->throughput->record($second, 1.0);
            $this->latencyAvg->record($second, $durationMs);
            $this->recordLatencySample($durationMs);

            $statusCode = (int) ($span->attributes['http.response.status_code'] ?? 0);
            $isError = $span->statusCode === self::ERROR_STATUS_CODE || $statusCode >= 500;
            if ($isError) {
                $this->totalErrors++;
            }

            $route = (string) ($span->attributes['http.route'] ?? $span->attributes['route_name'] ?? $span->name);
            $cacheHit = (bool) ($span->attributes['quiote.cache.hit'] ?? false);
            $this->routeStats->record($route, $durationMs, $isError, $cacheHit, $second);

            $entry = [
                'second' => $second,
                'traceId' => $span->traceId,
                'name' => $span->name,
                'statusCode' => $statusCode,
                'durationMs' => $durationMs,
                'isError' => $isError,
                'statusMessage' => $span->statusMessage,
            ];
            self::pushBounded($this->recentSpans, $entry, self::RECENT_FEED_CAP);
            if ($isError) {
                self::pushBounded($this->recentErrors, $entry, self::RECENT_ERRORS_CAP);
            }
        }
    }

    /** @param ReceivedMetric[] $metrics */
    public function ingestMetrics(array $metrics, int $second): void
    {
        foreach ($metrics as $metric) {
            foreach ($metric->dataPoints as $point) {
                $mode = $point->attributes['cpu.mode'] ?? null;
                $mean = $point->mean() ?? $point->value;

                match (true) {
                    $metric->name === 'quiote.worker.memory.rss' => $this->workerRssBytes = $point->value,
                    $metric->name === 'quiote.request.cpu.time' && $mode === 'user' => $this->cpuUser->record($second, $mean * 1000),
                    $metric->name === 'quiote.request.cpu.time' && $mode === 'system' => $this->cpuSystem->record($second, $mean * 1000),
                    $metric->name === 'quiote.request.memory.peak' => $this->memoryPeak->record($second, $mean),
                    default => null,
                };
            }
        }
    }

    public function snapshot(int $second): DashboardSnapshot
    {
        $throughputSeries = $this->throughput->series($second, 'sum');
        $latencySeries = $this->latencyAvg->series($second, 'avg');

        $recentThroughput = array_slice($throughputSeries, -5, preserve_keys: false);
        $requestsPerSecond = $recentThroughput === [] ? 0.0 : array_sum($recentThroughput) / count($recentThroughput);

        return new DashboardSnapshot(
            hasData: $this->totalRequests > 0,
            uptimeSeconds: $this->startedAtSecond !== null ? max(0, $second - $this->startedAtSecond) : 0,
            totalRequests: $this->totalRequests,
            totalErrors: $this->totalErrors,
            requestsPerSecond: $requestsPerSecond,
            errorRate: $this->totalRequests > 0 ? $this->totalErrors / $this->totalRequests : 0.0,
            avgLatencyMs: self::mean($this->latencySamples),
            p95LatencyMs: self::percentile($this->latencySamples, 0.95),
            maxLatencyMs: $this->latencySamples === [] ? 0.0 : max($this->latencySamples),
            cpuUserMs: self::lastNonDefault($this->cpuUser->series($second, 'last')),
            cpuSystemMs: self::lastNonDefault($this->cpuSystem->series($second, 'last')),
            memoryPeakBytes: self::lastNonDefault($this->memoryPeak->series($second, 'last')),
            workerRssBytes: $this->workerRssBytes,
            throughputSeries: array_values($throughputSeries),
            latencySeries: array_values($latencySeries),
            routeRows: $this->routeStats->rows(),
            recentSpans: array_reverse($this->recentSpans),
            recentErrors: array_reverse($this->recentErrors),
        );
    }

    private function recordLatencySample(float $ms): void
    {
        $this->latencySamples[] = $ms;
        if (count($this->latencySamples) > self::LATENCY_SAMPLE_CAP) {
            array_shift($this->latencySamples);
        }
    }

    /** @param list<mixed> $bag */
    private static function pushBounded(array &$bag, mixed $entry, int $cap): void
    {
        $bag[] = $entry;
        if (count($bag) > $cap) {
            array_shift($bag);
        }
    }

    /** @param float[] $values */
    private static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @param float[] $values */
    private static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }

        $sorted = $values;
        sort($sorted);
        $index = (int) ceil($p * count($sorted)) - 1;
        $index = max(0, min($index, count($sorted) - 1));

        return $sorted[$index];
    }

    /** The most recent non-zero-filled value in a chronological second=>value series, or 0.0. */
    private static function lastNonDefault(array $series): float
    {
        for (end($series); ($key = key($series)) !== null; prev($series)) {
            if (current($series) !== 0.0) {
                return current($series);
            }
        }

        return 0.0;
    }
}
