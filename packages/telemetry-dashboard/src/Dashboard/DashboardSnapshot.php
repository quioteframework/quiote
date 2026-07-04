<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * An immutable read of {@see DashboardState} at one point in time --
 * everything {@see \Quiote\Telemetry\Dashboard\DashboardView} needs to draw
 * one frame, with no further computation or I/O required. Kept separate from
 * `DashboardState` so rendering logic can be pure and unit-tested against a
 * hand-built snapshot without touching the mutable store at all.
 */
final class DashboardSnapshot
{
    /**
     * @param float[] $throughputSeries per-second request counts, chronological, one entry per second in the window
     * @param float[] $latencySeries per-second average latency (ms), chronological, aligned with $throughputSeries
     * @param list<array{route:string,count:int,avgMs:float,errorRate:float,cacheHitRate:float,lastSeenSecond:int}> $routeRows
     * @param list<array{second:int,traceId:string,name:string,statusCode:int,durationMs:float,isError:bool,statusMessage:string}> $recentSpans most recent first
     * @param list<array{second:int,traceId:string,name:string,statusCode:int,durationMs:float,isError:bool,statusMessage:string}> $recentErrors most recent first
     */
    public function __construct(
        public readonly bool $hasData,
        public readonly int $uptimeSeconds,
        public readonly int $totalRequests,
        public readonly int $totalErrors,
        public readonly float $requestsPerSecond,
        public readonly float $errorRate,
        public readonly float $avgLatencyMs,
        public readonly float $p95LatencyMs,
        public readonly float $maxLatencyMs,
        public readonly float $cpuUserMs,
        public readonly float $cpuSystemMs,
        public readonly float $memoryPeakBytes,
        public readonly float $workerRssBytes,
        public readonly array $throughputSeries,
        public readonly array $latencySeries,
        public readonly array $routeRows,
        public readonly array $recentSpans,
        public readonly array $recentErrors,
    ) {
    }
}
