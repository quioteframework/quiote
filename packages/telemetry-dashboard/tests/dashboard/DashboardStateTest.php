<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\DashboardState;
use Quiote\Telemetry\Dashboard\ReceivedDataPoint;
use Quiote\Telemetry\Dashboard\ReceivedMetric;
use Quiote\Telemetry\Dashboard\ReceivedSpan;

class DashboardStateTest extends TestCase
{
    private const OK = 1;
    private const ERROR = 2;

    public function testEmptyStateHasNoData(): void
    {
        $snapshot = (new DashboardState())->snapshot(1000);

        $this->assertFalse($snapshot->hasData);
        $this->assertSame(0, $snapshot->totalRequests);
        $this->assertSame(0, $snapshot->uptimeSeconds);
        $this->assertSame(0.0, $snapshot->errorRate);
    }

    public function testNonRootSpansAreIgnoredForTotalsAndFeeds(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span(parentSpanId: 'aaaa')], 1000);

        $snapshot = $state->snapshot(1000);

        $this->assertFalse($snapshot->hasData);
        $this->assertSame(0, $snapshot->totalRequests);
        $this->assertSame([], $snapshot->recentSpans);
    }

    public function testRootSpanIncrementsTotalsAndAppearsInRecentFeed(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span(name: 'GET /', durationMs: 12.0)], 1000);

        $snapshot = $state->snapshot(1000);

        $this->assertTrue($snapshot->hasData);
        $this->assertSame(1, $snapshot->totalRequests);
        $this->assertSame(0, $snapshot->totalErrors);
        $this->assertCount(1, $snapshot->recentSpans);
        $this->assertSame('GET /', $snapshot->recentSpans[0]['name']);
        $this->assertEqualsWithDelta(12.0, $snapshot->recentSpans[0]['durationMs'], 0.01);
    }

    public function testUptimeIsMeasuredFromFirstIngestedSpan(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span()], 1000);

        $this->assertSame(30, $state->snapshot(1030)->uptimeSeconds);
    }

    public function testErrorStatusCodeMarksTheSpanAsAnError(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span(statusCode: self::ERROR)], 1000);

        $snapshot = $state->snapshot(1000);

        $this->assertSame(1, $snapshot->totalErrors);
        $this->assertSame(1.0, $snapshot->errorRate);
        $this->assertCount(1, $snapshot->recentErrors);
    }

    public function test5xxHttpStatusAttributeMarksTheSpanAsAnErrorEvenWithOkSpanStatus(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span(statusCode: self::OK, attributes: ['http.response.status_code' => 500])], 1000);

        $this->assertSame(1, $state->snapshot(1000)->totalErrors);
    }

    public function test4xxIsNotTreatedAsAnError(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span(statusCode: self::OK, attributes: ['http.response.status_code' => 404])], 1000);

        $this->assertSame(0, $state->snapshot(1000)->totalErrors);
        $this->assertSame([], $state->snapshot(1000)->recentErrors);
    }

    public function testRouteIsResolvedFromHttpRouteAttributeThenRouteNameThenSpanName(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span(name: 'GET /orders/42', attributes: ['http.route' => '/orders/{id}'])], 1000);
        $state->ingestSpans([$this->span(name: 'GET /about', attributes: ['route_name' => 'about'])], 1001);
        $state->ingestSpans([$this->span(name: 'GET /contact')], 1002);

        $routes = array_column($state->snapshot(1002)->routeRows, 'route');

        $this->assertContains('/orders/{id}', $routes);
        $this->assertContains('about', $routes);
        $this->assertContains('GET /contact', $routes);
    }

    public function testCacheHitAttributeFeedsRouteCacheHitRate(): void
    {
        $state = new DashboardState();
        $state->ingestSpans([$this->span(name: 'GET /', attributes: ['quiote.cache.hit' => true])], 1000);
        $state->ingestSpans([$this->span(name: 'GET /', attributes: ['quiote.cache.hit' => false])], 1001);

        $row = $state->snapshot(1001)->routeRows[0];

        $this->assertSame(0.5, $row['cacheHitRate']);
    }

    public function testLatencyStatisticsAreComputedFromRawSpanDurations(): void
    {
        $state = new DashboardState();
        foreach ([10.0, 20.0, 30.0, 40.0, 100.0] as $i => $ms) {
            $state->ingestSpans([$this->span(durationMs: $ms)], 1000 + $i);
        }

        $snapshot = $state->snapshot(1004);

        $this->assertEqualsWithDelta(40.0, $snapshot->avgLatencyMs, 0.01);
        $this->assertSame(100.0, $snapshot->maxLatencyMs);
        $this->assertGreaterThanOrEqual(40.0, $snapshot->p95LatencyMs);
    }

    public function testRecentSpansAreOrderedMostRecentFirstAndBounded(): void
    {
        $state = new DashboardState();
        for ($i = 0; $i < 60; $i++) {
            $state->ingestSpans([$this->span(name: "span-$i")], 1000 + $i);
        }

        $snapshot = $state->snapshot(1059);

        $this->assertCount(50, $snapshot->recentSpans);
        $this->assertSame('span-59', $snapshot->recentSpans[0]['name']);
        $this->assertSame('span-10', $snapshot->recentSpans[49]['name']);
    }

    public function testWorkerRssGaugeUsesTheMostRecentlyIngestedValue(): void
    {
        $state = new DashboardState();
        $state->ingestMetrics([$this->gauge('quiote.worker.memory.rss', 100.0)], 1000);
        $state->ingestMetrics([$this->gauge('quiote.worker.memory.rss', 250.0)], 1001);

        $this->assertSame(250.0, $state->snapshot(1001)->workerRssBytes);
    }

    public function testCpuTimeIsSplitByModeAndConvertedToMilliseconds(): void
    {
        $state = new DashboardState();
        $state->ingestMetrics([
            $this->histogramMetric('quiote.request.cpu.time', sum: 0.006, count: 1, attributes: ['cpu.mode' => 'user']),
            $this->histogramMetric('quiote.request.cpu.time', sum: 0.001, count: 1, attributes: ['cpu.mode' => 'system']),
        ], 1000);

        $snapshot = $state->snapshot(1000);

        $this->assertEqualsWithDelta(6.0, $snapshot->cpuUserMs, 0.01);
        $this->assertEqualsWithDelta(1.0, $snapshot->cpuSystemMs, 0.01);
    }

    public function testMemoryPeakUsesTheHistogramMean(): void
    {
        $state = new DashboardState();
        $state->ingestMetrics([$this->histogramMetric('quiote.request.memory.peak', sum: 2_000_000.0, count: 2)], 1000);

        $this->assertSame(1_000_000.0, $state->snapshot(1000)->memoryPeakBytes);
    }

    public function testUnrecognizedMetricNamesAreIgnoredWithoutError(): void
    {
        $state = new DashboardState();
        $state->ingestMetrics([$this->gauge('some.unrelated.metric', 42.0)], 1000);

        $this->assertSame(0.0, $state->snapshot(1000)->workerRssBytes);
    }

    // --- helpers ---------------------------------------------------------

    private function span(
        string $name = 'GET /',
        ?string $parentSpanId = null,
        int $statusCode = self::OK,
        float $durationMs = 5.0,
        array $attributes = [],
    ): ReceivedSpan {
        return new ReceivedSpan(
            traceId: bin2hex(random_bytes(16)),
            spanId: bin2hex(random_bytes(8)),
            parentSpanId: $parentSpanId,
            name: $name,
            kind: 2,
            startTimeUnixNano: 0,
            endTimeUnixNano: (int) ($durationMs * 1_000_000),
            statusCode: $statusCode,
            statusMessage: '',
            attributes: $attributes,
            resourceAttributes: [],
        );
    }

    private function gauge(string $name, float $value): ReceivedMetric
    {
        return new ReceivedMetric($name, 'gauge', [new ReceivedDataPoint([], $value, null, 0)], []);
    }

    private function histogramMetric(string $name, float $sum, int $count, array $attributes = []): ReceivedMetric
    {
        return new ReceivedMetric($name, 'histogram', [new ReceivedDataPoint($attributes, $sum, $count, 0)], []);
    }
}
