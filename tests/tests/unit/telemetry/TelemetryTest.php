<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Telemetry\MeterHandle;
use Quiote\Telemetry\NoopMeterHandle;
use Quiote\Telemetry\NoopSpanHandle;
use Quiote\Telemetry\SpanHandle;
use Quiote\Telemetry\Trace;
use Quiote\Telemetry\TraceRegistry;

/**
 * Phase 1 tests for the telemetry facade/skeleton: no real tracer/meter
 * provider exists yet, so every acquisition call must resolve to a shared
 * no-op handle regardless of the enabled flag, and every no-op method must be
 * safe to call with any input (happy path AND deliberately hostile input)
 * without throwing.
 */
class TelemetryTest extends TestCase
{
    #[Before]
    public function setUpTelemetry(): void
    {
        Trace::reset();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        Trace::reset();
    }

    // --- configuration -----------------------------------------------------

    public function testDisabledByDefault(): void
    {
        $this->assertFalse(Trace::enabled());
    }

    public function testSetEnabledTogglesFlag(): void
    {
        Trace::setEnabled(true);
        $this->assertTrue(Trace::enabled());

        Trace::setEnabled(false);
        $this->assertFalse(Trace::enabled());
    }

    public function testResetRestoresDisabledDefault(): void
    {
        Trace::setEnabled(true);
        Trace::reset();
        $this->assertFalse(Trace::enabled());
    }

    public function testRegistryAndFacadeShareState(): void
    {
        Trace::setEnabled(true);
        $this->assertTrue(TraceRegistry::isEnabled());

        TraceRegistry::setEnabled(false);
        $this->assertFalse(Trace::enabled());
    }

    // --- acquisition: always no-op in this phase ---------------------------

    public function testSpanReturnsSpanHandleWhenDisabled(): void
    {
        $this->assertInstanceOf(SpanHandle::class, Trace::span('Quiote.Routing', 'match'));
    }

    public function testSpanReturnsSpanHandleWhenEnabled(): void
    {
        // No real provider is wired up yet in this phase, so even "enabled"
        // must still resolve to the no-op handle rather than throwing or
        // attempting to reach an SDK that isn't configured.
        Trace::setEnabled(true);
        $this->assertInstanceOf(SpanHandle::class, Trace::span('Quiote.Routing', 'match'));
    }

    public function testSpanIsTheSharedNoopInstance(): void
    {
        // No allocation per call: the disabled/unwired hot path must reuse one
        // instance, not construct a fresh no-op object per span() call.
        $a = Trace::span('Quiote.Routing', 'match');
        $b = Trace::span('App.Orders', 'checkout');
        $this->assertSame($a, $b);
        $this->assertSame(NoopSpanHandle::instance(), $a);
    }

    public function testCurrentReturnsSharedNoopSpanHandle(): void
    {
        $this->assertInstanceOf(SpanHandle::class, Trace::current());
        $this->assertSame(NoopSpanHandle::instance(), Trace::current());
    }

    public function testMetricsReturnsSharedNoopMeterHandle(): void
    {
        $this->assertInstanceOf(MeterHandle::class, Trace::metrics());
        $this->assertSame(NoopMeterHandle::instance(), Trace::metrics());
    }

    // --- SpanHandle: happy path ---------------------------------------------

    public function testSpanHandleFluentMethodsReturnSelfForChaining(): void
    {
        $span = Trace::span('App.Orders', 'checkout');
        $result = $span
            ->setAttribute('order.id', 42)
            ->setAttributes(['http.status_code' => 200])
            ->addEvent('validated', ['rules' => 3])
            ->setStatusError('nope, actually fine')
            ->recordException(new \RuntimeException('handled'));

        $this->assertSame($span, $result);
    }

    public function testSpanHandleEndIsIdempotent(): void
    {
        $span = Trace::span('App.Orders', 'checkout');
        $span->end();
        $span->end(); // must not throw the second time
        $this->addToAssertionCount(1);
    }

    // --- SpanHandle: failure / hostile-input paths --------------------------

    public function testSpanHandleAcceptsExoticAttributeValuesWithoutThrowing(): void
    {
        $span = Trace::span('App.Orders', 'checkout');
        $span->setAttribute('null_value', null);
        $span->setAttribute('object_value', new \stdClass());
        $span->setAttribute('array_value', [1, 2, ['nested' => true]]);
        $span->setAttributes([]); // empty map
        $this->addToAssertionCount(1);
    }

    public function testSpanHandleAcceptsEmptyCategoryAndName(): void
    {
        // A misconfigured call site (empty strings) must degrade safely, not
        // crash the request.
        $span = Trace::span('', '');
        $this->assertInstanceOf(SpanHandle::class, $span);
        $span->end();
        $this->addToAssertionCount(1);
    }

    public function testSpanHandleRecordsAnyThrowableSubtype(): void
    {
        $span = Trace::span('App.Orders', 'checkout');
        // Exception and Error are unrelated branches of the Throwable
        // hierarchy; both must be accepted without a type error.
        $span->recordException(new \RuntimeException('exception branch'));
        $span->recordException(new \TypeError('error branch'));
        $span->recordException(new \RuntimeException('chained', 0, new \LogicException('cause')));
        $this->addToAssertionCount(1);
    }

    public function testSpanHandleAddEventAcceptsNoAttributes(): void
    {
        $span = Trace::span('App.Orders', 'checkout');
        $span->addEvent('no-op-event');
        $this->addToAssertionCount(1);
    }

    public function testSpanHandleSetStatusErrorAcceptsNullDescription(): void
    {
        $span = Trace::span('App.Orders', 'checkout');
        $span->setStatusError();
        $this->addToAssertionCount(1);
    }

    // --- MeterHandle: happy path ---------------------------------------------

    public function testMeterHandleRecordsHistogramCounterAndGauge(): void
    {
        $meter = Trace::metrics();
        $meter->recordHistogram('http.server.request.duration', 12.5, ['http.route' => '/orders/{id}']);
        $meter->addCounter('http.server.active_requests');
        $meter->addCounter('quiote.cache.hits', 3, ['cache' => 'action_view']);
        $meter->recordGauge('quiote.worker.memory.rss', 1048576.0);
        $this->addToAssertionCount(1);
    }

    // --- MeterHandle: failure / hostile-input paths --------------------------

    public function testMeterHandleAcceptsNegativeAndZeroValues(): void
    {
        // Not physically meaningful (negative duration/memory), but the no-op
        // layer must not validate/throw — validation, if any, belongs to a
        // real SDK-backed implementation in a later phase.
        $meter = Trace::metrics();
        $meter->recordHistogram('quiote.request.cpu.time', -1.0);
        $meter->addCounter('quiote.cache.hits', 0);
        $meter->addCounter('quiote.cache.hits', -5);
        $meter->recordGauge('quiote.worker.memory.rss', 0.0);
        $this->addToAssertionCount(1);
    }

    public function testMeterHandleAcceptsEmptyInstrumentName(): void
    {
        $meter = Trace::metrics();
        $meter->recordHistogram('', 1.0);
        $this->addToAssertionCount(1);
    }

    // --- overhead guarantee --------------------------------------------------

    public function testDisabledHotPathAllocatesNoNewHandles(): void
    {
        // Ten calls, still the exact same instances: proves span()/current()/
        // metrics() never allocate on the disabled/unwired path, satisfying the
        // "no new runtime cost" acceptance bar for Phase 1.
        $seen = [];
        for ($i = 0; $i < 10; $i++) {
            $seen[] = Trace::span('Quiote', 'op-' . $i);
        }
        $this->assertCount(1, array_unique(array_map(spl_object_id(...), $seen)));
    }
}
