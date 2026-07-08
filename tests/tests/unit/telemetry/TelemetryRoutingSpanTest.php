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
use Quiote\Context;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Routing\Routing;
use Quiote\Telemetry\TelemetryBootstrap;
use Quiote\Telemetry\Trace;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests for the route-match span: attributes, the 404/405 outcomes, and
 * that a successful match renames whatever span is currently active to the
 * route's low-cardinality identity (this is also where the root span gets
 * its final name).
 */
class TelemetryRoutingSpanTest extends TestCase
{
    #[Before]
    public function setUpTelemetry(): void
    {
        TelemetryBootstrap::reset();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        TelemetryBootstrap::reset();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
        Config::remove('telemetry.spans.route');
    }

    private function enable(): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', 'always_on', true);
        TelemetryBootstrap::configureFromConfig();
    }

    private function controller(): \Quiote\Controller\Controller
    {
        return Context::getInstance('test')->getController();
    }

    /**
     * Mirrors RoutingMiddlewareTest's fixture: a single named route.
     * @param array<int, string> $methods
     */
    private function routingWithRoute(string $path, array $methods = []): Routing
    {
        return new class($path, $methods) extends Routing {
            /** @param array<int, string> $methods */
            public function __construct(private readonly string $path, private readonly array $methods)
            {
                parent::__construct();
            }
            protected function build(): array
            {
                $rc = new RouteCollection();
                $route = new Route(
                    $this->path,
                    ['_module' => 'TestModule', '_action' => 'TestAction'],
                    [],
                    [],
                    '',
                    [],
                    $this->methods
                );
                $rc->add('widgets_show', $route);
                $meta = ['widgets_show' => ['gen_path' => $this->path, 'cut' => false, 'path' => $this->path]];
                return [$rc, $meta];
            }
        };
    }

    private function dispatch(RoutingMiddleware $mw, ServerRequestInterface $req): ResponseInterface
    {
        $final = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        return $mw->process($req, $final);
    }

    /** @return array<int, mixed> */
    private function exportedSpans(): array
    {
        $exporter = TelemetryBootstrap::inMemorySpanExporter();
        if ($exporter === null) {
            throw new \RuntimeException('Expected an in-memory span exporter to be configured.');
        }
        return $exporter->getSpans();
    }

    // --- happy path ---------------------------------------------------------

    public function testMatchOpensARouteSpanWithHttpRouteAndRouteName(): void
    {
        $this->enable();
        $routing = $this->routingWithRoute('/widgets/{id}');
        $mw = new RoutingMiddleware($routing, $this->controller());

        $this->dispatch($mw, new ServerRequest('GET', '/widgets/42'));

        $spans = $this->exportedSpans();
        $match = array_values(array_filter($spans, static fn($s) => $s->getName() === 'match'));
        $this->assertCount(1, $match);
        $attrs = iterator_to_array($match[0]->getAttributes());
        $this->assertSame('/widgets/{id}', $attrs['http.route'], 'must use the raw path pattern, not the interpolated path');
        $this->assertSame('widgets_show', $attrs['route_name']);
    }

    public function testSuccessfulMatchRenamesTheActiveRootSpan(): void
    {
        $this->enable();
        $routing = $this->routingWithRoute('/widgets/{id}');
        $mw = new RoutingMiddleware($routing, $this->controller());

        // Simulate the root span TelemetryMiddleware would already have open
        // and activated by the time RoutingMiddleware runs.
        $root = Trace::span('Quiote.Http', 'GET /widgets/42');
        $this->dispatch($mw, new ServerRequest('GET', '/widgets/42'));
        $root->end();

        $spans = $this->exportedSpans();
        $names = array_map(static fn($s) => $s->getName(), $spans);
        $this->assertContains('GET /widgets/{id}', $names, 'the root span must be renamed to the low-cardinality route identity');
        $this->assertNotContains('GET /widgets/42', $names, 'the raw-path name must not survive the rename');
    }

    public function testUnrelatedActiveSpanAlsoGetsHttpRouteAttribute(): void
    {
        $this->enable();
        $routing = $this->routingWithRoute('/widgets/{id}');
        $mw = new RoutingMiddleware($routing, $this->controller());

        $root = Trace::span('Quiote.Http', 'root');
        $this->dispatch($mw, new ServerRequest('GET', '/widgets/42'));
        $root->end();

        $spans = $this->exportedSpans();
        $renamed = array_values(array_filter($spans, static fn($s) => $s->getName() === 'GET /widgets/{id}'));
        $this->assertCount(1, $renamed);
        $attrs = iterator_to_array($renamed[0]->getAttributes());
        $this->assertSame('/widgets/{id}', $attrs['http.route']);
        $this->assertSame('widgets_show', $attrs['route_name']);
    }

    // --- outcomes: 404 / 405 / OPTIONS -----------------------------------------

    public function testUnmatchedPathRecordsRouteMatchedFalse(): void
    {
        $this->enable();
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());

        $this->dispatch($mw, new ServerRequest('GET', '/does-not-exist'));

        $spans = $this->exportedSpans();
        $match = array_values(array_filter($spans, static fn($s) => $s->getName() === 'match'));
        $this->assertCount(1, $match);
        $attrs = iterator_to_array($match[0]->getAttributes());
        $this->assertFalse($attrs['route.matched']);
        $this->assertSame('404', $attrs['route.outcome']);
    }

    public function testMethodMismatchRecords405Outcome(): void
    {
        $this->enable();
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());

        $response = $this->dispatch($mw, new ServerRequest('GET', '/widgets'));

        $this->assertSame(405, $response->getStatusCode());
        $spans = $this->exportedSpans();
        $match = array_values(array_filter($spans, static fn($s) => $s->getName() === 'match'));
        $this->assertCount(1, $match, 'the span must still be exported even on the early-return 405 path');
        $attrs = iterator_to_array($match[0]->getAttributes());
        $this->assertSame('405', $attrs['route.outcome']);
    }

    public function testOptionsPassthroughStillEndsTheSpanExactlyOnce(): void
    {
        $this->enable();
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());

        $this->dispatch($mw, new ServerRequest('OPTIONS', '/widgets'));

        $spans = $this->exportedSpans();
        $match = array_values(array_filter($spans, static fn($s) => $s->getName() === 'match'));
        $this->assertCount(1, $match, 'exactly one span, not zero (leaked) or two (double-ended/double-exported)');
    }

    // --- depth toggle -------------------------------------------------------

    public function testSpansRouteFalseCreatesNoSpanAtAll(): void
    {
        $this->enable();
        Config::set('telemetry.spans.route', false, true);
        $routing = $this->routingWithRoute('/widgets/{id}');
        $mw = new RoutingMiddleware($routing, $this->controller());

        $this->dispatch($mw, new ServerRequest('GET', '/widgets/42'));

        $this->assertCount(0, $this->exportedSpans());
    }

    public function testSpansRouteFalseStillLeavesRootSpanUnrenamed(): void
    {
        // With the depth toggle off, RoutingMiddleware must not touch
        // Trace::current() either -- confirmed by checking the root span's
        // name survives untouched once it's later exported.
        $this->enable();
        Config::set('telemetry.spans.route', false, true);
        $routing = $this->routingWithRoute('/widgets/{id}');
        $mw = new RoutingMiddleware($routing, $this->controller());

        $root = Trace::span('Quiote.Http', 'GET /widgets/42');
        $this->dispatch($mw, new ServerRequest('GET', '/widgets/42'));
        $root->end();

        $spans = $this->exportedSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('GET /widgets/42', $spans[0]->getName());
    }
}
