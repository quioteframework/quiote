<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Routing\Routing;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Regression coverage for two bugs found together:
 * 1. Routing's RequestContext was never synced with the incoming request's HTTP
 *    method, so it stayed at the Symfony default of 'GET' forever. Once routes started
 *    carrying real method constraints (e.g. ['POST']), every legitimate POST/DELETE/etc.
 *    request to those routes incorrectly threw MethodNotAllowedException.
 * 2. RoutingMiddleware only caught ResourceNotFoundException (404), not
 *    MethodNotAllowedException (405) -- which extends \RuntimeException, not
 *    ResourceNotFoundException -- so a genuine method mismatch (e.g. a CORS OPTIONS
 *    preflight against a POST-only route) bubbled up as an uncaught 500.
 */
class RoutingMiddlewareTest extends TestCase
{
    private function controller(): \Quiote\Controller\Controller
    {
        $ctx = Context::getInstance('test');
        return $ctx->getController();
    }

    /** Builds an Routing instance with a single route constrained to the given methods (empty = unconstrained). */
    private function routingWithRoute(string $path, array $methods = []): Routing
    {
        return new class($path, $methods) extends Routing {
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
                $rc->add('test_route', $route);
                $meta = ['test_route' => ['gen_path' => $this->path, 'cut' => false, 'path' => $this->path]];
                return [$rc, $meta];
            }
        };
    }

    private function dispatch(RoutingMiddleware $mw, ServerRequestInterface $req): array
    {
        $handlerReached = false;
        $finalRequest = null;
        $final = new class($handlerReached, $finalRequest) implements RequestHandlerInterface {
            public bool $reached = false;
            public ?ServerRequestInterface $request = null;
            public function __construct(bool &$reached, ?ServerRequestInterface &$request) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $this->reached = true;
                $this->request = $r;
                return new Psr7Response(200);
            }
        };
        $response = $mw->process($req, $final);
        return ['response' => $response, 'reached' => $final->reached, 'request' => $final->request];
    }

    public function testMatchingMethodRoutesNormally(): void
    {
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());
        $req = new ServerRequest('POST', '/widgets');

        $result = $this->dispatch($mw, $req);

        $this->assertTrue($result['reached'], 'downstream handler should run for a matching method');
        $this->assertSame('TestModule', $result['request']->getAttribute('module'));
        $this->assertSame('TestAction', $result['request']->getAttribute('action'));
    }

    public function testMethodMismatchNonOptionsReturns405WithAllowHeader(): void
    {
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());
        $req = new ServerRequest('GET', '/widgets');

        $result = $this->dispatch($mw, $req);

        $this->assertFalse($result['reached'], 'middleware should short-circuit on method mismatch');
        $this->assertSame(405, $result['response']->getStatusCode());
        $this->assertSame('POST', $result['response']->getHeaderLine('Allow'));
    }

    public function testOptionsMethodMismatchPassesThroughUnrouted(): void
    {
        // Regression: this previously bubbled up as an uncaught MethodNotAllowedException (500),
        // breaking CORS preflight for any route with explicit method constraints.
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());
        $req = new ServerRequest('OPTIONS', '/widgets');

        $result = $this->dispatch($mw, $req);

        $this->assertTrue($result['reached'], 'OPTIONS should fall through to downstream CORS handling, not error');
        $this->assertNull($result['request']->getAttribute('module'), 'route should be left unmatched for OPTIONS preflight');
    }

    public function testUnconstrainedRouteMatchesAnyMethod(): void
    {
        $routing = $this->routingWithRoute('/widgets', []);
        $mw = new RoutingMiddleware($routing, $this->controller());

        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $result = $this->dispatch($mw, new ServerRequest($method, '/widgets'));
            $this->assertTrue($result['reached'], "$method should match an unconstrained route");
            $this->assertSame('TestModule', $result['request']->getAttribute('module'));
        }
    }

    public function testRequestContextMethodIsResyncedAcrossRequests(): void
    {
        // Regression: Routing's RequestContext defaulted to 'GET' and was never updated,
        // so a worker-reused routing instance would either always match GET or get stuck on
        // whatever method the first request happened to use. Verify each request is matched
        // against its own method, independent of what the previous request on the same
        // routing instance used.
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());

        $first = $this->dispatch($mw, new ServerRequest('POST', '/widgets'));
        $this->assertTrue($first['reached']);
        $this->assertSame('TestModule', $first['request']->getAttribute('module'));

        $second = $this->dispatch($mw, new ServerRequest('GET', '/widgets'));
        $this->assertFalse($second['reached']);
        $this->assertSame(405, $second['response']->getStatusCode());

        $third = $this->dispatch($mw, new ServerRequest('POST', '/widgets'));
        $this->assertTrue($third['reached']);
        $this->assertSame('TestModule', $third['request']->getAttribute('module'));
    }

    public function testNonExistentPathLeavesRouteUnmatched(): void
    {
        $routing = $this->routingWithRoute('/widgets', ['POST']);
        $mw = new RoutingMiddleware($routing, $this->controller());
        $req = new ServerRequest('GET', '/does-not-exist');

        $result = $this->dispatch($mw, $req);

        $this->assertTrue($result['reached'], '404 case should still fall through to downstream handling');
        $this->assertNull($result['request']->getAttribute('module'));
    }
}
