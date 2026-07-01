<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Middleware\PayloadParsingMiddleware;

final class PayloadParsingMiddlewareTest extends TestCase
{
    public function testValidJsonParsed(): void
    {
        $factory = new Nyholm\Psr7\Factory\Psr17Factory();
        $req = (new ServerRequest('POST','/api/foo'))
            ->withHeader('Content-Type','application/json')
            ->withBody($factory->createStream('{"a":1,"b":"x"}'));
        $mw = new PayloadParsingMiddleware(true);
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last = $r; return new Psr7Response(204); } };
        $resp = $mw->process($req,$final);
        $this->assertSame(204,$resp->getStatusCode());
        $this->assertSame(1,$final->last->getParsedBody()['a']);
        $this->assertSame('x',$final->last->getParsedBody()['b']);
    }

    public function testInvalidJsonStrictReturns400(): void
    {
        $factory = new Nyholm\Psr7\Factory\Psr17Factory();
        $req = (new ServerRequest('POST','/api/foo'))
            ->withHeader('Content-Type','application/json')
            ->withBody($factory->createStream('{ oops'));
        $mw = new PayloadParsingMiddleware(true);
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $resp = $mw->process($req,$final);
        $this->assertSame(400,$resp->getStatusCode());
    }

    public function testFormUrlencodedParsed(): void
    {
        $factory = new Nyholm\Psr7\Factory\Psr17Factory();
        $body = 'x=1&y=two';
        $req = (new ServerRequest('POST','/submit'))
            ->withHeader('Content-Type','application/x-www-form-urlencoded')
            ->withBody($factory->createStream($body));
        $mw = new PayloadParsingMiddleware(true);
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last=$r; return new Psr7Response(204);} };
        $resp = $mw->process($req,$final);
        $this->assertSame(204,$resp->getStatusCode());
        $this->assertSame('1',$final->last->getParsedBody()['x']);
        $this->assertSame('two',$final->last->getParsedBody()['y']);
    }
}
