<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Event\Events;
use Quiote\Event\Lifecycle\RequestMatchedEvent;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Routing\Routing;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Proves the framework lifecycle events fire at their real seams — not just
 * that the dispatcher works in isolation (that's EventDispatcherTest). Here a
 * real RoutingMiddleware match must emit RequestMatchedEvent to a registered
 * listener with the matched module/action.
 */
class LifecycleEventsIntegrationTest extends TestCase
{
    #[Before]
    #[After]
    public function resetEvents(): void
    {
        Events::reset();
    }

    public function testRoutingMiddlewareEmitsRequestMatchedEventOnMatch(): void
    {
        $captured = [];
        Events::listen(RequestMatchedEvent::class, function (RequestMatchedEvent $e) use (&$captured): void {
            $captured[] = [$e->module, $e->action, $e->routeName];
        });

        $mw = new RoutingMiddleware($this->routing('/widgets'), $this->controller());
        $mw->process(new ServerRequest('GET', 'http://localhost/widgets'), $this->passThrough());

        $this->assertCount(1, $captured);
        $this->assertSame(['TestModule', 'TestAction', 'test_route'], $captured[0]);
    }

    public function testNoRequestMatchedEventWhenPathDoesNotMatch(): void
    {
        $fired = false;
        Events::listen(RequestMatchedEvent::class, function () use (&$fired): void { $fired = true; });

        $mw = new RoutingMiddleware($this->routing('/widgets'), $this->controller());
        $mw->process(new ServerRequest('GET', 'http://localhost/nothing-here'), $this->passThrough());

        $this->assertFalse($fired);
    }

    public function testAListenerExceptionDoesNotBreakRouting(): void
    {
        Events::listen(RequestMatchedEvent::class, function (): void {
            throw new \RuntimeException('bad listener');
        });

        $mw = new RoutingMiddleware($this->routing('/widgets'), $this->controller());
        $response = $mw->process(new ServerRequest('GET', 'http://localhost/widgets'), $this->passThrough());

        // Routing still completes and reaches the handler despite the throwing listener.
        $this->assertSame(200, $response->getStatusCode());
    }

    private function controller(): \Quiote\Controller\Controller
    {
        return Context::getInstance('test')->getController();
    }

    private function routing(string $path): Routing
    {
        return new class($path) extends Routing {
            public function __construct(private readonly string $path)
            {
                parent::__construct();
            }
            protected function build(): array
            {
                $rc = new RouteCollection();
                $rc->add('test_route', new Route($this->path, ['_module' => 'TestModule', '_action' => 'TestAction']));
                $meta = ['test_route' => ['gen_path' => $this->path, 'cut' => false, 'path' => $this->path]];
                return [$rc, $meta];
            }
        };
    }

    private function passThrough(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
    }
}
