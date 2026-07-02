<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ErrorHandlingMiddlewareTest extends TestCase
{
    public function testExceptionConvertedTo500(): void
    {
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        $mw = new ErrorHandlingMiddleware();
        $handler = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new InvalidArgumentException('bad'); } };
        $req = new ServerRequest('GET', 'http://localhost/');
        $resp = $mw->process($req, $handler);
        $this->assertSame(400, $resp->getStatusCode(), 'InvalidArgumentException should map to 400');
        $this->assertFalse($resp->hasHeader('X-Quiote-Error-Type'), 'SafeRenderer must not leak the exception class via headers');
    }
}
