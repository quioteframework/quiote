<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Middleware\JsonBodyParsingMiddleware;

class JsonBodyParsingMiddlewareTest extends TestCase
{
    public function testValidJsonPopulatesParsedBody(): void
    {
        $factory = new Nyholm\Psr7\Factory\Psr17Factory();
        $req = (new ServerRequest('POST','/api/test'))
            ->withHeader('Content-Type','application/json')
            ->withBody($factory->createStream('{"foo": "bar", "num": 1}'));
        $mw = new JsonBodyParsingMiddleware();
        $final = new class implements RequestHandlerInterface { public ServerRequestInterface $last; public function handle(ServerRequestInterface $r): ResponseInterface { $this->last = $r; return new Psr7Response(204); } };
        $resp = $mw->process($req, $final);
        $this->assertSame(204, $resp->getStatusCode());
        $this->assertNotEmpty($final->last->getParsedBody());
        $this->assertSame('bar', $final->last->getParsedBody()['foo']);
        $this->assertSame(1, $final->last->getParsedBody()['num']);
    }

    public function testInvalidJsonReturns400InStrictMode(): void
    {
        $factory = new Nyholm\Psr7\Factory\Psr17Factory();
        $req = (new ServerRequest('POST','/api/test'))
            ->withHeader('Content-Type','application/json')
            ->withBody($factory->createStream('{ invalid'));
        $mw = new JsonBodyParsingMiddleware(true);
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $resp = $mw->process($req, $final);
        $this->assertSame(400, $resp->getStatusCode());
        $this->assertStringContainsString('invalid_json', (string)$resp->getBody());
    }
}
