<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogContext;
use Quiote\Logging\Sink\JsonStdoutSink;
use Quiote\Telemetry\NoopSpanHandle;
use Quiote\Telemetry\OtelMeterHandle;
use Quiote\Telemetry\OtelSpanHandle;
use Quiote\Telemetry\TelemetryBootstrap;
use Quiote\Telemetry\Trace;
use Quiote\Telemetry\TraceRegistry;

/**
 * Tests for the real SDK-backed telemetry bootstrap: provider
 * construction, worker-lifetime singleton behavior, request-boundary flush,
 * and — the important half — every way construction can fail (SDK exporter
 * misconfiguration, a missing PSR-18 client, hostile attribute values)
 * degrading to "telemetry stays off" rather than breaking the request.
 *
 * These tests exercise the REAL open-telemetry/* SDK (installed as a
 * require-dev dependency) via the "none" (in-memory) exporter, not a fake.
 */
class TelemetryBootstrapTest extends TestCase
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
        Config::remove('telemetry.service.name');
        Config::remove('telemetry.sampling.strategy');
        Config::remove('telemetry.sampling.ratio');
        Config::remove('telemetry.otlp.endpoint');
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL');
        \OpenTelemetry\API\Behavior\Internal\Logging::reset();
    }

    private function sink(Level $min = Level::Debug): JsonStdoutSink
    {
        return new JsonStdoutSink($min, [], 'php://stdout', $this->buf);
    }

    /** @return list<array<string,mixed>> */
    private function logRecords(): array
    {
        rewind($this->buf);
        $out = trim((string) stream_get_contents($this->buf));
        if ($out === '') {
            return [];
        }
        $records = [];
        foreach (explode("\n", $out) as $line) {
            $records[] = json_decode($line, true);
        }
        return $records;
    }

    private function enableInMemory(string $mode = 'simple'): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', $mode, true);
        // Pinned to always_on: these tests assert on provider/span/metric
        // plumbing, not sampling behavior (tests for that live in
        // TelemetrySamplingTest.php) — the default ratio sampler would make
        // span capture here nondeterministic.
        Config::set('telemetry.sampling.strategy', 'always_on', true);
    }

    // --- disabled by default -------------------------------------------------

    public function testDisabledByDefaultConfiguresNothing(): void
    {
        $this->assertFalse(TelemetryBootstrap::configureFromConfig());
        $this->assertFalse(Trace::enabled());
        $this->assertFalse(TraceRegistry::hasRealProvider());
        $this->assertInstanceOf(NoopSpanHandle::class, Trace::span('Quiote.Test', 'op'));
    }

    public function testFlushAfterRequestIsSafeWhenNeverConfigured(): void
    {
        TelemetryBootstrap::flushAfterRequest(); // must not throw
        $this->addToAssertionCount(1);
    }

    public function testShutdownIsSafeWhenNeverConfigured(): void
    {
        TelemetryBootstrap::shutdown(); // must not throw
        $this->addToAssertionCount(1);
    }

    // --- happy path: real provider via the in-memory exporter -----------------

    public function testConfiguresRealProviderWhenEnabled(): void
    {
        $this->enableInMemory();
        $this->assertTrue(TelemetryBootstrap::configureFromConfig());
        $this->assertTrue(Trace::enabled());
        $this->assertTrue(TraceRegistry::hasRealProvider());
    }

    public function testSpanIsRealHandleOnceConfigured(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'op1');
        $this->assertInstanceOf(OtelSpanHandle::class, $span);
        $span->end();
    }

    public function testSpanCategoryAndAttributesAreExported(): void
    {
        $this->enableInMemory(); // simple mode: SimpleSpanProcessor exports synchronously on end()
        TelemetryBootstrap::configureFromConfig();

        Trace::span('Quiote.Routing', 'match', ['http.route' => '/orders/{id}'])->end();

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans);
        $attrs = iterator_to_array($spans[0]->getAttributes());
        $this->assertSame('match', $spans[0]->getName());
        $this->assertSame('Quiote.Routing', $attrs['quiote.trace.category']);
        $this->assertSame('/orders/{id}', $attrs['http.route']);
    }

    public function testMetricsRecordRealInstruments(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $meter = Trace::metrics();
        $this->assertInstanceOf(OtelMeterHandle::class, $meter);
        $meter->recordHistogram('http.server.request.duration', 12.5, ['route' => '/x']);
        $meter->addCounter('quiote.cache.hits', 3);
        $meter->recordGauge('quiote.worker.memory.rss', 1048576.0);

        TelemetryBootstrap::flushAfterRequest();

        $metrics = TelemetryBootstrap::inMemoryMetricExporter()->collect();
        $names = array_map(static fn($m) => $m->name, $metrics);
        $this->assertContains('http.server.request.duration', $names);
        $this->assertContains('quiote.cache.hits', $names);
        $this->assertContains('quiote.worker.memory.rss', $names);
    }

    public function testCurrentReflectsActiveSpanThenFallsBackAfterEnd(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'outer');
        $current = Trace::current();
        $this->assertInstanceOf(OtelSpanHandle::class, $current);

        $span->end();
        $this->assertInstanceOf(NoopSpanHandle::class, Trace::current());
    }

    public function testConsoleExporterConfiguresSuccessfully(): void
    {
        // ConsoleSpanExporterFactory/ConsoleMetricExporterFactory use the SDK's
        // built-in 'stream' transport — no PSR-18 client required, unlike otlp.
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'console', true);
        Config::set('telemetry.export.mode', 'simple', true);

        $this->assertTrue(TelemetryBootstrap::configureFromConfig());
        $this->assertTrue(Trace::enabled());
    }

    // --- worker-lifetime singleton behavior -----------------------------------

    public function testProviderIsSingletonAcrossRepeatedCalls(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();
        $first = TraceRegistry::tracerProvider();

        // A second configureFromConfig() call (e.g. a stray extra bootstrap
        // call) must not rebuild — exactly one provider per worker.
        TelemetryBootstrap::configureFromConfig();
        $this->assertSame($first, TraceRegistry::tracerProvider());
    }

    public function testFlushAfterRequestDoesNotRebuildProvider(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();
        $tracerProvider = TraceRegistry::tracerProvider();
        $meterProvider = TraceRegistry::meterProvider();

        // Simulate several requests being served by the same worker.
        for ($i = 0; $i < 5; $i++) {
            Trace::span('Quiote.Test', 'req-' . $i)->end();
            TelemetryBootstrap::flushAfterRequest();
        }

        $this->assertSame($tracerProvider, TraceRegistry::tracerProvider());
        $this->assertSame($meterProvider, TraceRegistry::meterProvider());
        $this->assertCount(5, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testResetAllowsRebuildingAFreshProvider(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();
        $first = TraceRegistry::tracerProvider();

        TelemetryBootstrap::reset();
        $this->assertFalse(TraceRegistry::hasRealProvider());

        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();
        $second = TraceRegistry::tracerProvider();

        $this->assertNotSame($first, $second, 'reset() must yield a genuinely new provider, simulating a fresh worker');
    }

    public function testShutdownIsIdempotent(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();
        Trace::span('Quiote.Test', 'op')->end();

        TelemetryBootstrap::shutdown();
        TelemetryBootstrap::shutdown(); // must not throw the second time
        $this->addToAssertionCount(1);
    }

    // --- failure paths ---------------------------------------------------------

    public function testOtlpWorksOutOfTheBoxViaTheBundledCurlTransport(): void
    {
        // Previously this repo shipped no PSR-18 client, so `telemetry.exporter =
        // otlp` degraded to disabled (the SDK's php-http/discovery found no
        // client). Quiote now ships its own zero-dependency PSR-18 client
        // (Quiote\Http\Client\CurlTransport) and hands it to the OTLP exporter
        // factories (TelemetryBootstrap::otlpTransportFactory()), so OTLP export
        // works with no extra Composer package — building the provider succeeds
        // and telemetry comes up.
        if (!\function_exists('curl_init')) {
            $this->markTestSkipped('curl extension required for the bundled OTLP transport');
        }

        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'otlp', true);
        Config::set('telemetry.otlp.endpoint', 'http://127.0.0.1:4318', true);

        $this->assertTrue(TelemetryBootstrap::configureFromConfig());
        $this->assertTrue(Trace::enabled());
        $this->assertTrue(TraceRegistry::hasRealProvider());
        // A real (recording) span, not the disabled no-op handle. End it so it
        // doesn't linger on the OTel context stack into the next test.
        $span = Trace::span('Quiote.Test', 'op');
        $this->assertNotInstanceOf(NoopSpanHandle::class, $span);
        $span->end();
    }

    public function testUnknownExporterValueFallsBackToInMemoryWithWarning(): void
    {
        Log::setDefaultLevel(Level::Debug);
        Log::addSink($this->sink());

        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'not-a-real-exporter', true);
        Config::set('telemetry.export.mode', 'simple', true);

        // A typo in telemetry.exporter must not disable telemetry entirely —
        // it degrades to the safe local in-memory exporter instead.
        $this->assertTrue(TelemetryBootstrap::configureFromConfig());
        $this->assertTrue(Trace::enabled());
        $this->assertNotNull(TelemetryBootstrap::inMemorySpanExporter());

        $records = $this->logRecords();
        $this->assertNotEmpty($records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertStringContainsString('not-a-real-exporter', $records[0]['message']);
    }

    public function testHostileAttributeValuesDoNotCrashARealSpan(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'op');
        // An object is not a valid OTel attribute value (bool|int|float|string|
        // array|null only) — the real SDK must not let this crash the request.
        $span->setAttribute('bad', new stdClass());
        $span->setAttribute('also_bad', fopen('php://memory', 'r'));
        $span->setAttributes(['ok' => 'fine', 'also_bad_array' => [1, 'two']]);
        $span->end();

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans, 'the span itself must still be exported despite bad attribute values');
    }

    public function testRecordExceptionOnRealSpanDoesNotThrowForAnyThrowableSubtype(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'op');
        $span->recordException(new \RuntimeException('exception branch'));
        $span->recordException(new \TypeError('error branch'));
        $span->end();
        $this->addToAssertionCount(1);
    }

    public function testSpanEndIsIdempotentForRealSpan(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'op');
        $span->end();
        $span->end(); // must not throw, must not export twice

        $this->assertCount(1, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testEmptyCategoryAndNameDoNotCrashRealSpanCreation(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('', '');
        $span->end();

        $this->assertCount(1, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    // --- regression: Trace::current() must be a non-owning borrowed reference ---
    //
    // Found via the real OTel Collector end-to-end verification
    // (docs/OPENTELEMETRY_E2E_VERIFICATION.md), not by unit tests — it only
    // manifests when a SEPARATE piece of code (not the span's owner) borrows
    // Trace::current() into a local variable that goes out of scope before
    // the owner is done with the span. See Quiote\Telemetry\OtelSpanHandle's
    // class docblock for the full story.

    public function testDestructingABorrowedCurrentHandleDoesNotEndTheRealSpan(): void
    {
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'owner');

        // Simulates a separate function/middleware borrowing the active span
        // into a local variable and returning, destructing its own reference
        // — this must NOT end the real span the owner still holds.
        (function (): void {
            $borrowed = Trace::current();
            $borrowed->setAttribute('touched.by.borrower', true);
        })();

        // The owner must still be able to set attributes/status and end it
        // normally — none of that would work if the borrower's destructor
        // had already ended the underlying span.
        $span->setAttribute('owner.finished', true);
        $span->end();

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans, 'exactly one span: the borrower must not have triggered a premature/duplicate export');
        $attrs = iterator_to_array($spans[0]->getAttributes());
        $this->assertTrue($attrs['touched.by.borrower'], 'the borrower\'s own mutation must still have taken effect');
        $this->assertTrue($attrs['owner.finished'], 'the owner must still be able to mutate the span after the borrower went out of scope');
    }

    public function testErrorStatusSetByOwnerAfterABorrowerWentOutOfScopeSurvivesExport(): void
    {
        // The exact shape of the real bug: an exception unwinds through a
        // borrower's stack frame (destructing its Trace::current() reference)
        // BEFORE the owner's own catch/finally records the error and ends
        // the span.
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'owner');
        $error = new \RuntimeException('simulated failure');

        try {
            (function () use ($error): void {
                // Borrows the active span (e.g. RoutingMiddleware renaming
                // the root span), then this frame unwinds via the exception
                // below — destructing $borrowed along the way.
                $borrowed = Trace::current();
                $borrowed->setAttribute('route.matched', true);
                throw $error;
            })();
        } catch (\RuntimeException) {
            // The owner (e.g. TelemetryMiddleware's finally block) records
            // the failure on its OWN span reference after the borrower's
            // frame has already unwound.
            $span->recordException($error)->setStatusError($error->getMessage());
        } finally {
            $span->end();
        }

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('Error', $spans[0]->getStatus()->getCode(), 'the owner\'s error status must survive even though a borrower\'s reference to the same span was destructed first');
        $this->assertNotEmpty($spans[0]->getEvents(), 'recordException() must still have taken effect');
    }

    public function testExplicitEndOnABorrowedCurrentHandleStillEndsTheRealSpan(): void
    {
        // The flip side: ownsLifecycle=false only suppresses IMPLICIT
        // destructor-triggered ending. An explicit ->end() call on a
        // Trace::current() handle must still end the real span.
        $this->enableInMemory();
        TelemetryBootstrap::configureFromConfig();

        $span = Trace::span('Quiote.Test', 'owner');
        $current = Trace::current();
        $this->assertInstanceOf(\Quiote\Telemetry\OtelSpanHandle::class, $current);

        $current->end();

        $this->assertCount(1, TelemetryBootstrap::inMemorySpanExporter()->getSpans(), 'explicit end() via a borrowed handle must still export the span');
    }
}
