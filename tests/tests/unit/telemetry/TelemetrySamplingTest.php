<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogContext;
use Quiote\Logging\Sink\JsonStdoutSink;
use Quiote\Telemetry\SpanKind;
use Quiote\Telemetry\TelemetryBootstrap;
use Quiote\Telemetry\Trace;

/**
 * Tests for head-based sampling: the ratio sampler, the always_on/
 * always_off strategies, the force-sample escape hatch, and the specific
 * acceptance criteria that metrics are NEVER sampled and that a child of a
 * locally-sampled parent is always
 * sampled regardless of ratio.
 */
class TelemetrySamplingTest extends TestCase
{
    /** @var resource */
    private $buf;

    #[Before]
    public function setUpTelemetry(): void
    {
        TelemetryBootstrap::reset();
        Log::reset();
        LogContext::clear();
        $this->buf = fopen('php://memory', 'r+');
        \OpenTelemetry\API\Behavior\Internal\Logging::disable();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        TelemetryBootstrap::reset();
        Log::reset();
        LogContext::clear();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
        Config::remove('telemetry.sampling.ratio');
        \OpenTelemetry\API\Behavior\Internal\Logging::reset();
    }

    private function configure(string $strategy, float $ratio = 0.1): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', $strategy, true);
        Config::set('telemetry.sampling.ratio', $ratio, true);
        TelemetryBootstrap::configureFromConfig();
    }

    private function sink(): JsonStdoutSink
    {
        return new JsonStdoutSink(Level::Debug, [], 'php://stdout', $this->buf);
    }

    private function logRecords(): array
    {
        rewind($this->buf);
        $out = trim((string) stream_get_contents($this->buf));
        if ($out === '') {
            return [];
        }
        return array_map('json_decode', explode("\n", $out), array_fill(0, substr_count($out, "\n") + 1, true));
    }

    // --- ratio sampling: acceptance criteria ----------------------------------

    public function testRatioZeroSamplesNoSpans(): void
    {
        $this->configure('parentbased_traceidratio', 0.0);

        for ($i = 0; $i < 10; $i++) {
            Trace::span('Quiote.Test', 'op-' . $i)->end();
        }

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testRatioZeroStillRecordsAllMetrics(): void
    {
        // Central claim: sampling applies to traces only.
        $this->configure('parentbased_traceidratio', 0.0);

        for ($i = 0; $i < 5; $i++) {
            Trace::span('Quiote.Test', 'op-' . $i)->end();
        }
        Trace::metrics()->addCounter('http.server.request.count', 5);
        TelemetryBootstrap::flushAfterRequest();

        $metrics = TelemetryBootstrap::inMemoryMetricExporter()->collect();
        $names = array_map(static fn($m) => $m->name, $metrics);
        $this->assertContains('http.server.request.count', $names);
    }

    public function testRatioOneSamplesEverySpan(): void
    {
        $this->configure('parentbased_traceidratio', 1.0);

        for ($i = 0; $i < 10; $i++) {
            Trace::span('Quiote.Test', 'op-' . $i)->end();
        }

        $this->assertCount(10, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    // --- always_on / always_off ------------------------------------------------

    public function testAlwaysOnIgnoresARatioOfZero(): void
    {
        $this->configure('always_on', 0.0);

        Trace::span('Quiote.Test', 'op')->end();

        $this->assertCount(1, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testAlwaysOffIgnoresARatioOfOne(): void
    {
        $this->configure('always_off', 1.0);

        Trace::span('Quiote.Test', 'op')->end();

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testUnknownStrategyFallsBackToParentBasedRatioWithWarning(): void
    {
        Log::setDefaultLevel(Level::Debug);
        Log::addSink($this->sink());

        $this->configure('not-a-real-strategy', 1.0);

        // Falls back to parentbased_traceidratio using the *configured* ratio
        // (1.0 here), not some other hardcoded behavior.
        Trace::span('Quiote.Test', 'op')->end();
        $this->assertCount(1, TelemetryBootstrap::inMemorySpanExporter()->getSpans());

        $records = $this->logRecords();
        $this->assertNotEmpty($records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertStringContainsString('not-a-real-strategy', $records[0]['message']);
    }

    // --- force-sample escape hatch ----------------------------------------------

    public function testForceSampleAttributeBypassesAZeroRatio(): void
    {
        $this->configure('parentbased_traceidratio', 0.0);

        Trace::span('Quiote.Test', 'op', ['quiote.force_sample' => true])->end();

        $this->assertCount(1, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testWithoutForceSampleAttributeARatioOfZeroStillDrops(): void
    {
        $this->configure('parentbased_traceidratio', 0.0);

        Trace::span('Quiote.Test', 'op')->end(); // no force_sample attribute

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testForceSampleTruthyValueMustBeExactlyBooleanTrue(): void
    {
        // A stray truthy-but-not-`true` value (e.g. the string "1") must NOT
        // force sampling — only a literal boolean true, matching what
        // TelemetryMiddleware actually sets. Guards against accidental
        // loosening of the check.
        $this->configure('parentbased_traceidratio', 0.0);

        Trace::span('Quiote.Test', 'op', ['quiote.force_sample' => '1'])->end();

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    // --- acceptance: child of a sampled local parent is always sampled ---------

    public function testChildOfAForceSampledParentIsAlwaysSampledDespiteZeroRatio(): void
    {
        $this->configure('parentbased_traceidratio', 0.0);

        $parent = Trace::span('Quiote.Test', 'parent', ['quiote.force_sample' => true]);
        // Child created while the parent is the active span (activate() was
        // called on it) inherits ParentBased's "local parent sampled" branch,
        // which defaults to AlwaysOnSampler — regardless of the 0.0 ratio.
        $child = Trace::span('Quiote.Test', 'child');
        $child->end();
        $parent->end();

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(2, $spans, 'both parent (force-sampled) and child (inherits) must be exported');

        $names = array_map(static fn($s) => $s->getName(), $spans);
        $this->assertContains('parent', $names);
        $this->assertContains('child', $names);
    }

    public function testChildOfAnUnsampledParentIsNotIndependentlySampled(): void
    {
        // Contrast case: ratio 0.0, no force-sample anywhere — the parent is
        // dropped, and the child (inheriting "local parent not sampled") must
        // be dropped too, not independently re-rolled against the ratio.
        $this->configure('parentbased_traceidratio', 0.0);

        $parent = Trace::span('Quiote.Test', 'parent');
        $child = Trace::span('Quiote.Test', 'child');
        $child->end();
        $parent->end();

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }
}
