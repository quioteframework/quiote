<?php
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Exception\Rendering\ExceptionRenderer;
use Quiote\Exception\Rendering\ExceptionRendererRegistry;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Request\RequestState;
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

    public function testRegisteredSafeRendererOverridesTheDefaultSafeRenderer(): void
    {
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        ExceptionRendererRegistry::setSafeRenderer(
            static fn(): ExceptionRenderer => new class implements ExceptionRenderer {
                public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface
                {
                    return new Response($status, [], 'Custom error page');
                }
            }
        );

        try {
            $mw = new ErrorHandlingMiddleware();
            $handler = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new RuntimeException('bad'); } };
            $req = new ServerRequest('GET', 'http://localhost/');
            $resp = $mw->process($req, $handler);

            $this->assertSame(500, $resp->getStatusCode());
            $this->assertSame('Custom error page', (string) $resp->getBody());
        } finally {
            ExceptionRendererRegistry::reset();
        }
    }

    /**
     * The seam packages/replay's RecorderMiddleware reads to see an exception that
     * ErrorHandlingMiddleware itself catches and renders -- see RecorderMiddlewareTest's
     * testCassetteCapturesTheExceptionErrorHandlingMiddlewareCaughtAndRendered() for the
     * end-to-end case this exists for.
     */
    public function testPublishesTheCaughtExceptionOntoRequestStateWhenAContextIsProvided(): void
    {
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        $request = new ServerRequest('GET', 'http://localhost/');
        $current = \Quiote\Request\WebRequest::fromPsr($request);
        $requestState = new RequestState(
            static function () use (&$current) {
                return $current;
            },
            static function ($replacement) use (&$current): void {
                $current = $replacement instanceof \Quiote\Request\WebRequest
                    ? $replacement
                    : \Quiote\Request\WebRequest::fromPsr($replacement);
            },
        );
        $container = new Container();
        $container->set(RequestState::class, $requestState);
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        $mw = new ErrorHandlingMiddleware(null, $context);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('bad');
            }
        };

        $mw->process($request, $handler);

        $published = $requestState->current()->getAttribute(Throwable::class);
        $this->assertInstanceOf(RuntimeException::class, $published);
        $this->assertSame('bad', $published->getMessage());
    }

    /**
     * Regression: publishing the exception used to attach it to process()'s own $request
     * parameter -- the pre-routing request this outermost middleware itself received -- rather
     * than to RequestState::current(). That republished a stale, attribute-less request as the
     * new "current" one, discarding whatever RoutingMiddleware/DispatchMiddleware had already
     * published before the exception was thrown (module/action/route_name -- exactly what
     * RecorderMiddleware's `resolved` cassette section reads back). A request that errors must
     * still resolve to its real route, not to null, once the exception is also on it.
     */
    public function testPublishingTheExceptionPreservesAttributesRoutingAlreadyPublished(): void
    {
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        $request = new ServerRequest('GET', 'http://localhost/widgets');
        // Simulates what RoutingMiddleware publishes before dispatch reaches (and throws from)
        // the action -- current() already reflects this by the time ErrorHandlingMiddleware
        // catches anything, since routing sits inside it in the real pipeline.
        $routed = \Quiote\Request\WebRequest::fromPsr($request)
            ->withAttribute('module', 'Widgets')
            ->withAttribute('action', 'Show');
        $current = $routed;
        $requestState = new RequestState(
            static function () use (&$current) {
                return $current;
            },
            static function ($replacement) use (&$current): void {
                $current = $replacement instanceof \Quiote\Request\WebRequest
                    ? $replacement
                    : \Quiote\Request\WebRequest::fromPsr($replacement);
            },
        );
        $container = new Container();
        $container->set(RequestState::class, $requestState);
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        $mw = new ErrorHandlingMiddleware(null, $context);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('bad');
            }
        };

        $mw->process($request, $handler);

        $afterward = $requestState->current();
        $this->assertSame('Widgets', $afterward->getAttribute('module'));
        $this->assertSame('Show', $afterward->getAttribute('action'));
        $this->assertInstanceOf(RuntimeException::class, $afterward->getAttribute(Throwable::class));
    }

    /**
     * tryGet(), not get(): a test double's fabricated Context/Container legitimately has no
     * RequestState bound, and that must stay a no-op rather than a crash.
     */
    public function testDoesNotCrashWhenNoRequestStateIsBoundInTheContainer(): void
    {
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        $container = new Container();
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        $mw = new ErrorHandlingMiddleware(null, $context);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('bad');
            }
        };

        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/'), $handler);

        $this->assertSame(500, $resp->getStatusCode());
    }

    public function testNoRegisteredSafeRendererFallsBackToDefaultSafeRenderer(): void
    {
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        $mw = new ErrorHandlingMiddleware();
        $handler = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { throw new RuntimeException('bad'); } };
        $req = new ServerRequest('GET', 'http://localhost/');
        $resp = $mw->process($req, $handler);

        $this->assertSame(500, $resp->getStatusCode());
        $this->assertStringContainsString('Internal Server Error', (string) $resp->getBody());
    }
}
