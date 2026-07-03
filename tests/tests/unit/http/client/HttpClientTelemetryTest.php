<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Nyholm\Psr7\Response;
use Quiote\Config\Config;
use Quiote\Http\Client\HttpClient;
use Quiote\Http\Client\HttpClientConfig;
use Quiote\Telemetry\SpanKind;
use Quiote\Telemetry\TelemetryBootstrap;
use Quiote\Telemetry\Trace;
use Quiote\Test\Http\Client\RecordingTransport;

/**
 * The outbound half of docs/OPENTELEMETRY_PLAN.md Phase 7: when telemetry is
 * on, HttpClient injects W3C traceparent into the outgoing request (via
 * Psr7HeaderSetter) so a downstream service continues the trace, and opens a
 * CLIENT-kind span. When telemetry is off it's a pure pass-through.
 */
class HttpClientTelemetryTest extends TestCase
{
    #[Before]
    public function setUp(): void
    {
        TelemetryBootstrap::reset();
        Trace::reset();
    }

    #[After]
    public function tearDown(): void
    {
        TelemetryBootstrap::reset();
        Trace::reset();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
    }

    private function enableTelemetry(): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', 'always_on', true);
        TelemetryBootstrap::configureFromConfig();
    }

    private function client(RecordingTransport $transport): HttpClient
    {
        $config = new HttpClientConfig();
        $config->transport($transport);
        return HttpClient::fromConfig($config);
    }

    public function testInjectsTraceparentIntoOutboundRequestUnderAnActiveSpan(): void
    {
        $this->enableTelemetry();
        $transport = new RecordingTransport(new Response(200));

        // An active server span so there's a valid context to propagate from.
        $root = Trace::span('Quiote.Test', 'root', [], SpanKind::Server);
        try {
            $this->client($transport)->get('https://downstream.example/api');
        } finally {
            $root->end();
        }

        $traceparent = $transport->lastRequest()->getHeaderLine('traceparent');
        $this->assertNotSame('', $traceparent, 'expected a traceparent header to be injected');
        // W3C format: version-traceid(32 hex)-spanid(16 hex)-flags(2 hex)
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/', $traceparent);
        // Same trace as the active root span.
        $this->assertStringContainsString((string) $root->traceId(), $traceparent);
    }

    public function testNoTraceparentInjectedWhenTelemetryDisabled(): void
    {
        // Telemetry not enabled: pure pass-through, no propagation.
        $transport = new RecordingTransport(new Response(200));
        $this->client($transport)->get('https://downstream.example/api');

        $this->assertSame('', $transport->lastRequest()->getHeaderLine('traceparent'));
    }

    public function testRequestStillSucceedsWithTelemetryOnAndNoActiveSpan(): void
    {
        $this->enableTelemetry();
        $transport = new RecordingTransport(new Response(204));

        // No active parent span — inject() simply finds no valid context and
        // writes nothing; the request must still go through fine.
        $response = $this->client($transport)->get('https://downstream.example/api');

        $this->assertSame(204, $response->getStatusCode());
    }
}
