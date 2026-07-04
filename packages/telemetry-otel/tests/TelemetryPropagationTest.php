<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Logging\LogContext;
use Quiote\Middleware\TelemetryMiddleware;
use Quiote\Telemetry\Psr7HeaderGetter;
use Quiote\Telemetry\TelemetryBootstrap;

/**
 * Phase 7 tests for inbound W3C trace-context propagation and log/trace
 * correlation: the plan's exact acceptance criteria — "an inbound traceparent
 * produces spans whose parent is the upstream span" and "every log line
 * during a request carries the same trace_id as that request's root span,"
 * including the specific claim that this holds even for a sampled-out span.
 */
class TelemetryPropagationTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const PARENT_SPAN_ID = '00f067aa0ba902b7';

    #[Before]
    public function setUpTelemetry(): void
    {
        TelemetryBootstrap::reset();
        LogContext::clear();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        TelemetryBootstrap::reset();
        LogContext::clear();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
        Config::remove('telemetry.sampling.ratio');
    }

    private function enable(string $strategy = 'always_on', float $ratio = 1.0): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', $strategy, true);
        Config::set('telemetry.sampling.ratio', $ratio, true);
        TelemetryBootstrap::configureFromConfig();
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $mw = new TelemetryMiddleware();
        $final = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        return $mw->process($request, $final);
    }

    private function withTraceparent(ServerRequestInterface $request, string $traceId = self::TRACE_ID, string $spanId = self::PARENT_SPAN_ID, string $flags = '01'): ServerRequestInterface
    {
        return $request->withHeader('traceparent', "00-$traceId-$spanId-$flags");
    }

    // --- inbound propagation: root span joins the upstream trace ------------------

    public function testInboundTraceparentBecomesTheRootSpansTraceId(): void
    {
        $this->enable();
        $this->dispatch($this->withTraceparent(new ServerRequest('GET', '/x')));

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame(self::TRACE_ID, $spans[0]->getContext()->getTraceId());
    }

    public function testInboundTraceparentBecomesTheRootSpansParent(): void
    {
        $this->enable();
        $this->dispatch($this->withTraceparent(new ServerRequest('GET', '/x')));

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertSame(self::PARENT_SPAN_ID, $spans[0]->getParentSpanId());
    }

    public function testWithoutATraceparentARandomTraceIdIsGenerated(): void
    {
        $this->enable();
        $this->dispatch(new ServerRequest('GET', '/x'));

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans);
        $this->assertNotSame(self::TRACE_ID, $spans[0]->getContext()->getTraceId());
        $this->assertSame('0000000000000000', $spans[0]->getParentSpanId(), 'no parent for a fresh root trace');
    }

    public function testUnsampledUpstreamTraceparentIsRespected(): void
    {
        // A remote parent explicitly marked "not sampled" (flags=00) must
        // suppress export via ParentBased's remote-parent-not-sampled branch,
        // independent of our own ratio.
        $this->enable('parentbased_traceidratio', 1.0);
        $this->dispatch($this->withTraceparent(new ServerRequest('GET', '/x'), flags: '00'));

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    // --- malformed / hostile input does not crash the request -------------------

    public function testMalformedTraceparentDoesNotCrashAndStartsAFreshTrace(): void
    {
        $this->enable();
        $response = $this->dispatch(
            (new ServerRequest('GET', '/x'))->withHeader('traceparent', 'not-a-real-traceparent-header')
        );

        $this->assertSame(200, $response->getStatusCode());
        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans);
        $this->assertNotSame(self::TRACE_ID, $spans[0]->getContext()->getTraceId());
    }

    public function testTraceparentWithInvalidTraceIdDoesNotCrash(): void
    {
        $this->enable();
        $response = $this->dispatch(
            $this->withTraceparent(new ServerRequest('GET', '/x'), traceId: '00000000000000000000000000000000') // all-zero: invalid
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testEmptyTraceparentHeaderDoesNotCrash(): void
    {
        $this->enable();
        $response = $this->dispatch((new ServerRequest('GET', '/x'))->withHeader('traceparent', ''));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDisabledTelemetryIgnoresTraceparentEntirely(): void
    {
        // Telemetry off: must not even attempt extraction.
        $response = $this->dispatch($this->withTraceparent(new ServerRequest('GET', '/x')));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(LogContext::isEmpty(), 'no correlation when telemetry never ran');
    }

    // --- log/trace correlation ---------------------------------------------------

    public function testLogContextIsEnrichedWithTheRootSpansTraceAndSpanId(): void
    {
        $this->enable();
        $this->dispatch($this->withTraceparent(new ServerRequest('GET', '/x')));

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $current = LogContext::current();
        $this->assertSame(self::TRACE_ID, $current['trace_id']);
        $this->assertSame($spans[0]->getContext()->getSpanId(), $current['span_id']);
    }

    public function testLogCorrelationHoldsEvenForASampledOutSpan(): void
    {
        // The plan's specific claim: "this works even for sampled-out traces
        // (the ids still exist)" — ratio 0.0 drops the span from export, but
        // the trace ID must still land in LogContext.
        $this->enable('parentbased_traceidratio', 0.0);
        $this->dispatch(new ServerRequest('GET', '/x'));

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans(), 'sanity check: really dropped');
        $current = LogContext::current();
        $this->assertArrayHasKey('trace_id', $current);
        $this->assertNotEmpty($current['trace_id']);
    }

    public function testNoCorrelationWhenTelemetryDisabled(): void
    {
        $this->dispatch(new ServerRequest('GET', '/x'));
        $this->assertTrue(LogContext::isEmpty());
    }

    // --- Psr7HeaderGetter --------------------------------------------------------

    public function testHeaderGetterReadsPsr7RequestHeaders(): void
    {
        $getter = new Psr7HeaderGetter();
        $request = (new ServerRequest('GET', '/x'))->withHeader('traceparent', '00-abc-def-01');

        $this->assertSame('00-abc-def-01', $getter->get($request, 'traceparent'));
        $this->assertNull($getter->get($request, 'tracestate'));
        $this->assertContains('traceparent', $getter->keys($request));
    }

    public function testHeaderGetterReturnsSafeDefaultsForNonMessageCarrier(): void
    {
        $getter = new Psr7HeaderGetter();
        $this->assertNull($getter->get(['traceparent' => 'x'], 'traceparent'));
        $this->assertSame([], $getter->keys(['traceparent' => 'x']));
    }
}
