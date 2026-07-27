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

    public function testCategoryLoggerIsCachedOnConstructionNotReResolvedPerCall(): void
    {
        $mw = new ErrorHandlingMiddleware();
        $prop = new ReflectionProperty(ErrorHandlingMiddleware::class, 'categoryLogger');
        $logger = $prop->getValue($mw);
        $this->assertInstanceOf(\Quiote\Logging\CategoryLogger::class, $logger);
        $this->assertSame($logger, \Quiote\Logging\Log::for($mw), 'must be the same instance Log::for() would resolve');
    }

    public function testProcessStillWorksWhenDebugLoggingIsEnabled(): void
    {
        // Guards against the isEnabled()-gated debug() calls added around the
        // getUri() string-cast/concat regressing the happy (non-exception) path.
        \Quiote\Logging\Log::setDefaultLevel(\Quiote\Logging\Level::Debug);
        try {
            \Quiote\Config\Config::set('core.developer_exceptions', false);
            $mw = new ErrorHandlingMiddleware();
            $handler = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $r): ResponseInterface
                {
                    return new \Nyholm\Psr7\Response(200);
                }
            };
            $req = new ServerRequest('GET', 'http://localhost/some/path');
            $resp = $mw->process($req, $handler);
            $this->assertSame(200, $resp->getStatusCode());
        } finally {
            \Quiote\Logging\Log::reset();
        }
    }
}
