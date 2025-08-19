<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Middleware\ContentNegotiationMiddleware;
use Agavi\Controller\AgaviController;
use Agavi\AgaviContext;

class ContentNegotiationMiddlewareTest extends TestCase
{
    private function makeController(): AgaviController
    {
        // Use global singleton context accessor since constructor is protected.
        // This mirrors other tests invoking AgaviContext::getInstance() style patterns (heuristic fallback).
        if(method_exists(AgaviContext::class,'getInstance')) {
            $ctx = AgaviContext::getInstance('test');
        } else {
            // Fallback: use reflection to invoke protected ctor for test (avoid modifying core just for test)
            $ref = new ReflectionClass(AgaviContext::class);
            $ctor = $ref->getConstructor();
            $ctor->setAccessible(true);
            // Protected signature parameters extraction
            $ctx = $ref->newInstanceWithoutConstructor();
            $ctor->invokeArgs($ctx, ['test', [], true]);
        }
        $controller = $ctx->getController();
        $controller->startup();
        return $controller;
    }

    public function testFormatQueryParamWins(): void
    {
        $controller = $this->makeController();
        $mw = new ContentNegotiationMiddleware($controller);
        $req = new ServerRequest('GET','/foo?format=json');
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last = $r; return new Psr7Response(200); } };
        $resp = $mw->process($req,$final);
        $this->assertSame(200,$resp->getStatusCode());
        $this->assertSame('json',$final->last->getAttribute('output_type'));
    }

    public function testAcceptHeaderUsedWhenNoExplicitFormat(): void
    {
        $controller = $this->makeController();
        $mw = new ContentNegotiationMiddleware($controller);
        $req = (new ServerRequest('GET','/foo'))
            ->withHeader('Accept','application/json, text/html;q=0.8');
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last = $r; return new Psr7Response(200); } };
        $resp = $mw->process($req,$final);
        $this->assertSame('json',$final->last->getAttribute('output_type'));
    }

    public function testRouteAttributePreserved(): void
    {
        $controller = $this->makeController();
        $mw = new ContentNegotiationMiddleware($controller);
        $req = (new ServerRequest('GET','/foo'))
            ->withAttribute('output_type','xml')
            ->withHeader('Accept','application/json');
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last = $r; return new Psr7Response(200); } };
        $mw->process($req,$final);
        $this->assertSame('xml',$final->last->getAttribute('output_type'));
    }
}
