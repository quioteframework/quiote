<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * Per-route aggregates (count, avg latency, error %, cache-hit %, last seen)
 * for the dashboard's route table. Route names come from telemetry data an
 * instrumented app controls (`http.route`/`route_name` span attributes,
 * see {@see DashboardState}), so the number of distinct routes is bounded
 * defensively at record()-time -- not just at display time -- to keep memory
 * bounded even if a hostile/buggy app emits an unbounded number of distinct
 * route labels: once the tracked-route cap is hit, anything new folds into a
 * single `(other)` bucket rather than growing the map forever.
 */
final class RouteStats
{
    private const MAX_TRACKED_ROUTES = 200;
    private const OTHER_LABEL = '(other)';

    /** @var array<string, array{count:int,errorCount:int,totalMs:float,cacheHits:int,lastSeenSecond:int}> */
    private array $routes = [];

    public function record(string $route, float $durationMs, bool $isError, bool $cacheHit, int $second): void
    {
        if (!isset($this->routes[$route]) && count($this->routes) >= self::MAX_TRACKED_ROUTES && $route !== self::OTHER_LABEL) {
            $route = self::OTHER_LABEL;
        }

        $entry = &$this->routes[$route];
        $entry ??= ['count' => 0, 'errorCount' => 0, 'totalMs' => 0.0, 'cacheHits' => 0, 'lastSeenSecond' => $second];

        $entry['count']++;
        $entry['totalMs'] += $durationMs;
        $entry['lastSeenSecond'] = $second;
        if ($isError) {
            $entry['errorCount']++;
        }
        if ($cacheHit) {
            $entry['cacheHits']++;
        }
    }

    /**
     * @return list<array{route:string,count:int,avgMs:float,errorRate:float,cacheHitRate:float,lastSeenSecond:int}>
     *         sorted by request count, descending
     */
    public function rows(int $limit = 25): array
    {
        $rows = [];
        foreach ($this->routes as $route => $r) {
            $rows[] = [
                'route' => $route,
                'count' => $r['count'],
                'avgMs' => $r['count'] > 0 ? $r['totalMs'] / $r['count'] : 0.0,
                'errorRate' => $r['count'] > 0 ? $r['errorCount'] / $r['count'] : 0.0,
                'cacheHitRate' => $r['count'] > 0 ? $r['cacheHits'] / $r['count'] : 0.0,
                'lastSeenSecond' => $r['lastSeenSecond'],
            ];
        }

        usort($rows, static fn(array $a, array $b) => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, $limit);
    }
}
