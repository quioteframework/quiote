<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Middleware\StealthMiddleware;

final class StealthMiddlewareTest extends TestCase
{
    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function testDisabledByDefaultLeavesHeadersUntouched(): void
    {
        $mw = new StealthMiddleware();
        $req = new ServerRequest('GET', '/');
        $response = (new Psr7Response(200))
            ->withHeader('X-Powered-By', 'Quiote')
            ->withHeader('X-Quiote-Trace', 'a,b');

        $resp = $mw->process($req, $this->handlerReturning($response));

        $this->assertTrue($resp->hasHeader('X-Powered-By'));
        $this->assertTrue($resp->hasHeader('X-Quiote-Trace'));
    }

    public function testEnabledStripsPoweredByAndQuioteHeaders(): void
    {
        $mw = new StealthMiddleware(true);
        $req = new ServerRequest('GET', '/');
        $response = (new Psr7Response(200))
            ->withHeader('X-Powered-By', 'Quiote')
            ->withHeader('X-Quiote-Trace', 'a,b')
            ->withHeader('X-Quiote-Validation-State', 'invalid')
            ->withHeader('X-Quiote-Debug', '1')
            ->withHeader('X-Quiote-Cache-Hit', 'true');

        $resp = $mw->process($req, $this->handlerReturning($response));

        $this->assertFalse($resp->hasHeader('X-Powered-By'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Trace'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Validation-State'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Debug'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Cache-Hit'));
    }

    public function testEnabledStripsQuioteHeaderRegardlessOfCase(): void
    {
        $mw = new StealthMiddleware(true);
        $req = new ServerRequest('GET', '/');
        $response = (new Psr7Response(200))->withHeader('x-quiote-trace', 'a,b');

        $resp = $mw->process($req, $this->handlerReturning($response));

        $this->assertFalse($resp->hasHeader('X-Quiote-Trace'));
    }

    public function testEnabledStripsConfiguredAdditionalHeaderWithoutQuiotePrefix(): void
    {
        $mw = new StealthMiddleware(true, ['X-Custom-Backend']);
        $req = new ServerRequest('GET', '/');
        $response = (new Psr7Response(200))->withHeader('X-Custom-Backend', 'Quiote/4.0');

        $resp = $mw->process($req, $this->handlerReturning($response));

        $this->assertFalse($resp->hasHeader('X-Custom-Backend'));
    }

    public function testEnabledLeavesUnrelatedHeadersUntouched(): void
    {
        $mw = new StealthMiddleware(true);
        $req = new ServerRequest('GET', '/');
        $response = (new Psr7Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $resp = $mw->process($req, $this->handlerReturning($response));

        $this->assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
    }

    public function testEnabledWithNothingToStripReturnsResponseUnchanged(): void
    {
        $mw = new StealthMiddleware(true);
        $req = new ServerRequest('GET', '/');
        $response = (new Psr7Response(200))->withHeader('Content-Type', 'text/html');

        $resp = $mw->process($req, $this->handlerReturning($response));

        $this->assertSame('text/html', $resp->getHeaderLine('Content-Type'));
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testEnabledStripsHeadersOnErrorResponses(): void
    {
        $mw = new StealthMiddleware(true);
        $req = new ServerRequest('GET', '/missing');
        $errorResponse = (new Psr7Response(404))
            ->withHeader('X-Powered-By', 'Quiote')
            ->withHeader('X-Quiote-Debug', '1');

        $resp = $mw->process($req, $this->handlerReturning($errorResponse));

        $this->assertSame(404, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('X-Powered-By'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Debug'));
    }
}
