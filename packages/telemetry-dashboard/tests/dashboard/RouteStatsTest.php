<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\RouteStats;

class RouteStatsTest extends TestCase
{
    public function testAggregatesCountAvgErrorRateAndCacheHitRatePerRoute(): void
    {
        $stats = new RouteStats();
        $stats->record('GET /', 10.0, false, true, 100);
        $stats->record('GET /', 20.0, false, false, 101);
        $stats->record('GET /', 30.0, true, false, 102);

        $rows = $stats->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('GET /', $rows[0]['route']);
        $this->assertSame(3, $rows[0]['count']);
        $this->assertSame(20.0, $rows[0]['avgMs']);
        $this->assertEqualsWithDelta(1 / 3, $rows[0]['errorRate'], 0.0001);
        $this->assertEqualsWithDelta(1 / 3, $rows[0]['cacheHitRate'], 0.0001);
        $this->assertSame(102, $rows[0]['lastSeenSecond']);
    }

    public function testRowsAreSortedByCountDescending(): void
    {
        $stats = new RouteStats();
        $stats->record('GET /rare', 1.0, false, false, 1);
        $stats->record('GET /popular', 1.0, false, false, 1);
        $stats->record('GET /popular', 1.0, false, false, 2);
        $stats->record('GET /popular', 1.0, false, false, 3);

        $rows = $stats->rows();

        $this->assertSame('GET /popular', $rows[0]['route']);
        $this->assertSame('GET /rare', $rows[1]['route']);
    }

    public function testRowsRespectsTheLimit(): void
    {
        $stats = new RouteStats();
        for ($i = 0; $i < 10; $i++) {
            $stats->record("GET /route-$i", 1.0, false, false, 1);
        }

        $this->assertCount(3, $stats->rows(limit: 3));
    }

    public function testDistinctRoutesBeyondTheTrackingCapFoldIntoOther(): void
    {
        $stats = new RouteStats();
        for ($i = 0; $i < 205; $i++) {
            $stats->record("GET /route-$i", 1.0, false, false, 1);
        }

        $rows = $stats->rows(limit: 1000);
        $routeNames = array_column($rows, 'route');

        $this->assertContains('(other)', $routeNames);
        $this->assertLessThanOrEqual(201, count($rows));

        $other = $rows[array_search('(other)', $routeNames, true)];
        $this->assertGreaterThan(0, $other['count']);
    }

    public function testRecordingTheSameOverflowRouteRepeatedlyKeepsAccumulatingOnOther(): void
    {
        $stats = new RouteStats();
        for ($i = 0; $i < 200; $i++) {
            $stats->record("GET /route-$i", 1.0, false, false, 1);
        }
        // These two both overflow into "(other)" -- confirms it accumulates
        // rather than being overwritten each time.
        $stats->record('GET /overflow-a', 1.0, false, false, 1);
        $stats->record('GET /overflow-b', 1.0, false, false, 1);

        $rows = $stats->rows(limit: 1000);
        $other = $rows[array_search('(other)', array_column($rows, 'route'), true)];

        $this->assertSame(2, $other['count']);
    }

    public function testNoRoutesYieldsNoRows(): void
    {
        $this->assertSame([], (new RouteStats())->rows());
    }
}
