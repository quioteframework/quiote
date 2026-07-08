<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Middleware\TimingMiddleware;

/**
 * json_encode() is typed to return string|false; TimingMiddleware previously fed its result
 * straight into withHeader() without checking for false. The array being encoded here only
 * ever contains a rounded float, so json_encode() cannot realistically fail — but PHPStan
 * cannot know that statically, and the guard is the correct defensive fix rather than a cast.
 */
final class TimingMiddlewareTest extends TestCase
{
    public function testEmitHeaderAddsTimingHeaderWithEncodedTotalMs(): void
    {
        $mw = new TimingMiddleware(true);
        $req = new ServerRequest('GET', '/');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        $resp = $mw->process($req, $handler);
        $this->assertTrue($resp->hasHeader('X-Quiote-Timing'));
        $decoded = json_decode($resp->getHeaderLine('X-Quiote-Timing'), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('total_ms', $decoded);
        $this->assertIsFloat($decoded['total_ms']);
    }

    public function testHeaderDisabledByDefaultLeavesResponseUntouched(): void
    {
        $mw = new TimingMiddleware();
        $req = new ServerRequest('GET', '/');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        $resp = $mw->process($req, $handler);
        $this->assertFalse($resp->hasHeader('X-Quiote-Timing'));
    }
}
