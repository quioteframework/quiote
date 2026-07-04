<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\DashboardSnapshot;
use Quiote\Telemetry\Dashboard\DashboardView;
use Symfony\Component\Tui\Render\Renderer;

/**
 * DashboardView::build() is a pure function (snapshot in, widget tree out --
 * see its own docblock), so it's tested here by rendering the tree with a
 * real symfony/tui Renderer and asserting on the resulting text, with no
 * terminal or running Tui/event loop required.
 */
class DashboardViewTest extends TestCase
{
    private function render(DashboardSnapshot $snapshot, string $service = 'demo', string $address = 'http://127.0.0.1:4318'): string
    {
        $tree = DashboardView::build($snapshot, $service, $address);
        $renderer = new Renderer();

        return implode("\n", $renderer->render($tree, 120, 40));
    }

    public function testEmptySnapshotShowsWaitingState(): void
    {
        $output = $this->render($this->emptySnapshot());

        $this->assertStringContainsString('Waiting for telemetry', $output);
        $this->assertStringContainsString('http://127.0.0.1:4318', $output);
    }

    public function testRootFillsAvailableHeightEvenBeforeAnyDataArrives(): void
    {
        // The "waiting" branch has no ChartWidget descendant to propagate
        // fill-ness up through -- expandVertically() must be set explicitly
        // on the root, or the dashboard box only grows to fill the terminal
        // once the first span arrives, not on startup.
        $root = DashboardView::build($this->emptySnapshot(), 'demo', 'http://127.0.0.1:4318');

        $this->assertTrue($root->isVerticallyExpanded());
    }

    public function testRootStillFillsAvailableHeightOnceDataArrives(): void
    {
        $root = DashboardView::build($this->snapshotWithOneRoute(), 'demo', 'http://127.0.0.1:4318');

        $this->assertTrue($root->isVerticallyExpanded());
    }

    public function testChartsFillMoreVerticalSpaceThanASingleRowInATallTerminal(): void
    {
        $tree = DashboardView::build($this->snapshotWithOneRoute(), 'demo', 'http://127.0.0.1:4318');
        $lines = (new Renderer())->render($tree, 120, 60);

        // Bordered rows always have "|"-padding on both sides, so a "blank"
        // interior line is never whitespace-only -- find the row range
        // between the "Throughput" header and the next section ("Worker
        // RSS") instead of looking for a blank-line boundary.
        $throughputIndex = self::findLineIndex($lines, 'Throughput');
        $workerRssIndex = self::findLineIndex($lines, 'Worker RSS');

        $this->assertNotNull($throughputIndex);
        $this->assertNotNull($workerRssIndex);

        $chartRows = $workerRssIndex - $throughputIndex - 1;

        // The single-line Spark sparkline this replaced would only ever
        // occupy 1 row; the fill-driven ChartWidget must occupy more once
        // it has real vertical room to grow into.
        $this->assertGreaterThan(1, $chartRows);
    }

    /** @param string[] $lines */
    private static function findLineIndex(array $lines, string $needle): ?int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        return null;
    }

    public function testHeaderShowsServiceNameAndTotals(): void
    {
        $output = $this->render($this->snapshotWithOneRoute());

        $this->assertStringContainsString('demo', $output);
        $this->assertStringContainsString('1 req', $output);
    }

    public function testRouteTableListsEachRoute(): void
    {
        $output = $this->render($this->snapshotWithOneRoute());

        $this->assertStringContainsString('GET /', $output);
    }

    public function testRecentFeedShowsSpanNameAndStatus(): void
    {
        $output = $this->render($this->snapshotWithOneRoute());

        $this->assertStringContainsString('200', $output);
    }

    public function testErrorEntriesIncludeTheStatusMessage(): void
    {
        $snapshot = $this->snapshotWithAnError();
        $output = $this->render($snapshot);

        $this->assertStringContainsString('Boom', $output);
        $this->assertStringContainsString('500', $output);
    }

    public function testZeroStatusCodeOnAnErrorSpanIsShownAsErrNotZero(): void
    {
        $entry = ['second' => 10, 'traceId' => 'x', 'name' => 'GET /boom', 'statusCode' => 0, 'durationMs' => 5.0, 'isError' => true, 'statusMessage' => 'Boom!'];
        $snapshot = new DashboardSnapshot(
            hasData: true,
            uptimeSeconds: 10,
            totalRequests: 1,
            totalErrors: 1,
            requestsPerSecond: 0.1,
            errorRate: 1.0,
            avgLatencyMs: 5.0,
            p95LatencyMs: 5.0,
            maxLatencyMs: 5.0,
            cpuUserMs: 1.0,
            cpuSystemMs: 1.0,
            memoryPeakBytes: 1024.0,
            workerRssBytes: 1024.0,
            throughputSeries: [1.0],
            latencySeries: [5.0],
            routeRows: [['route' => '/boom', 'count' => 1, 'avgMs' => 5.0, 'errorRate' => 1.0, 'cacheHitRate' => 0.0, 'lastSeenSecond' => 10]],
            recentSpans: [$entry],
            recentErrors: [$entry],
        );

        $output = $this->render($snapshot);

        $this->assertStringContainsString('ERR', $output);
        $this->assertStringNotContainsString('  0  ', $output);
    }

    public function testHostileSpanNameCannotInjectEscapeSequences(): void
    {
        $malicious = "GET /\x1b[2J\x1b[Hpwned";
        $snapshot = new DashboardSnapshot(
            hasData: true,
            uptimeSeconds: 5,
            totalRequests: 1,
            totalErrors: 0,
            requestsPerSecond: 1.0,
            errorRate: 0.0,
            avgLatencyMs: 5.0,
            p95LatencyMs: 5.0,
            maxLatencyMs: 5.0,
            cpuUserMs: 1.0,
            cpuSystemMs: 1.0,
            memoryPeakBytes: 1024.0,
            workerRssBytes: 1024.0,
            throughputSeries: [1.0],
            latencySeries: [5.0],
            routeRows: [['route' => $malicious, 'count' => 1, 'avgMs' => 5.0, 'errorRate' => 0.0, 'cacheHitRate' => 0.0, 'lastSeenSecond' => 1]],
            recentSpans: [['second' => 1, 'traceId' => 'abc', 'name' => $malicious, 'statusCode' => 200, 'durationMs' => 5.0, 'isError' => false, 'statusMessage' => '']],
            recentErrors: [],
        );

        $output = $this->render($snapshot);

        $this->assertStringNotContainsString("\x1b[2J", $output);
        $this->assertStringNotContainsString("\x1b[H", $output);
    }

    public function testWaitingStateNeverIncludesARouteTableOrRecentFeed(): void
    {
        $output = $this->render($this->emptySnapshot());

        $this->assertStringNotContainsString('reqs', $output);
        $this->assertStringNotContainsString('Recent', $output);
    }

    private function emptySnapshot(): DashboardSnapshot
    {
        return new DashboardSnapshot(
            hasData: false,
            uptimeSeconds: 0,
            totalRequests: 0,
            totalErrors: 0,
            requestsPerSecond: 0.0,
            errorRate: 0.0,
            avgLatencyMs: 0.0,
            p95LatencyMs: 0.0,
            maxLatencyMs: 0.0,
            cpuUserMs: 0.0,
            cpuSystemMs: 0.0,
            memoryPeakBytes: 0.0,
            workerRssBytes: 0.0,
            throughputSeries: [],
            latencySeries: [],
            routeRows: [],
            recentSpans: [],
            recentErrors: [],
        );
    }

    private function snapshotWithOneRoute(): DashboardSnapshot
    {
        return new DashboardSnapshot(
            hasData: true,
            uptimeSeconds: 10,
            totalRequests: 1,
            totalErrors: 0,
            requestsPerSecond: 0.5,
            errorRate: 0.0,
            avgLatencyMs: 6.0,
            p95LatencyMs: 6.0,
            maxLatencyMs: 6.0,
            cpuUserMs: 1.0,
            cpuSystemMs: 0.5,
            memoryPeakBytes: 2048.0,
            workerRssBytes: 1_000_000.0,
            throughputSeries: [1.0, 0.0],
            latencySeries: [6.0, 0.0],
            routeRows: [['route' => 'GET /', 'count' => 1, 'avgMs' => 6.0, 'errorRate' => 0.0, 'cacheHitRate' => 1.0, 'lastSeenSecond' => 10]],
            recentSpans: [['second' => 10, 'traceId' => 'abc', 'name' => 'GET /', 'statusCode' => 200, 'durationMs' => 6.0, 'isError' => false, 'statusMessage' => '']],
            recentErrors: [],
        );
    }

    private function snapshotWithAnError(): DashboardSnapshot
    {
        $entry = ['second' => 12, 'traceId' => 'xyz', 'name' => 'GET /boom', 'statusCode' => 500, 'durationMs' => 11.0, 'isError' => true, 'statusMessage' => 'Boom!'];

        return new DashboardSnapshot(
            hasData: true,
            uptimeSeconds: 12,
            totalRequests: 1,
            totalErrors: 1,
            requestsPerSecond: 0.1,
            errorRate: 1.0,
            avgLatencyMs: 11.0,
            p95LatencyMs: 11.0,
            maxLatencyMs: 11.0,
            cpuUserMs: 2.0,
            cpuSystemMs: 1.0,
            memoryPeakBytes: 4096.0,
            workerRssBytes: 2_000_000.0,
            throughputSeries: [1.0],
            latencySeries: [11.0],
            routeRows: [['route' => '/boom', 'count' => 1, 'avgMs' => 11.0, 'errorRate' => 1.0, 'cacheHitRate' => 0.0, 'lastSeenSecond' => 12]],
            recentSpans: [$entry],
            recentErrors: [$entry],
        );
    }
}
