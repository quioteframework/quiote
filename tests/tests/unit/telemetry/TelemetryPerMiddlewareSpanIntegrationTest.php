<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Nyholm\Psr7\ServerRequest;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Telemetry\TelemetryBootstrap;

/**
 * Phase 8 integration test: `telemetry.spans.middleware` wired through the
 * REAL default `MiddlewarePipeline`, not the decorator in isolation. Run in
 * a separate process — Context/Config/MiddlewarePipeline all carry
 * process-global state (same isolation MiddlewareAttributeOrderingTest and
 * TelemetryContainerRegistrationTest use for the same reason).
 */
#[RunTestsInSeparateProcesses]
class TelemetryPerMiddlewareSpanIntegrationTest extends TestCase
{
    private function enable(bool $spanMiddleware): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', 'always_on', true);
        Config::set('telemetry.spans.middleware', $spanMiddleware, true);
        TelemetryBootstrap::configureFromConfig();
    }

    private function dispatch(): void
    {
        $pipeline = new MiddlewarePipeline(Context::getInstance());
        try {
            $pipeline->handle(new ServerRequest('GET', 'http://localhost/test'));
        } catch (\Throwable) {
            // Some requests bottom out in an error response rendered by
            // ErrorHandlingMiddleware rather than a clean 200 — irrelevant
            // here; only the span tree this run produced matters.
        }
    }

    public function testDefaultProducesNoPerMiddlewareSpans(): void
    {
        $this->enable(spanMiddleware: false);
        $this->dispatch();

        $names = array_map(
            static fn($s) => $s->getName(),
            TelemetryBootstrap::inMemorySpanExporter()->getSpans()
        );
        $this->assertNotContains(RoutingMiddleware::class, $names);
    }

    public function testEnabledProducesASpanPerMiddlewareNamedByFqcn(): void
    {
        $this->enable(spanMiddleware: true);
        $this->dispatch();

        $names = array_map(
            static fn($s) => $s->getName(),
            TelemetryBootstrap::inMemorySpanExporter()->getSpans()
        );
        foreach ([
            \Quiote\Middleware\ErrorHandlingMiddleware::class,
            \Quiote\Middleware\TelemetryMiddleware::class,
            \Quiote\Middleware\SessionMiddleware::class,
            \Quiote\Middleware\RoutingMiddleware::class,
        ] as $fqcn) {
            $this->assertContains($fqcn, $names, "$fqcn should have its own span");
        }
    }

    public function testRouteMatchSpanNestsUnderItsOwnMiddlewareSpan(): void
    {
        // Proves this is a real nested tree, not a flat list of same-level
        // spans: the "match" span RoutingMiddleware opens internally must be
        // a child of RoutingMiddleware's own wrapper span.
        $this->enable(spanMiddleware: true);
        $this->dispatch();

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $byName = [];
        foreach ($spans as $s) {
            $byName[$s->getName()] = $s;
        }

        $this->assertArrayHasKey(RoutingMiddleware::class, $byName);
        $this->assertArrayHasKey('match', $byName);
        $this->assertSame(
            $byName[RoutingMiddleware::class]->getContext()->getSpanId(),
            $byName['match']->getParentSpanId(),
        );
    }
}
