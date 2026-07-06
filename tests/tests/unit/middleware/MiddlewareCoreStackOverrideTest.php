<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Covers the deliberate escape hatch that lets an app replace Quiote's entire
 * built-in middleware stack (MiddlewareCatalog::replaceCoreStack()), gated
 * behind an exact acknowledgement string so it can't be triggered by accident.
 */
class MiddlewareCoreStackOverrideTest extends TestCase
{
    public function setUp(): void
    {
        MiddlewareCatalog::initialize([]);
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
    }

    #[\Override]
    public function tearDown(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
    }

    private static function passthru(string $marker): callable
    {
        return static fn(): MiddlewareInterface => new class($marker) implements MiddlewareInterface {
            public function __construct(private string $marker) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(200, [], $this->marker);
            }
        };
    }

    public function testWrongAcknowledgementIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [],
            'yes i am sure'
        );
    }

    public function testCorrectAcknowledgementInstallsTheOverride(): void
    {
        $this->assertFalse(MiddlewareCatalog::hasCoreStackOverride());
        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );
        $this->assertTrue(MiddlewareCatalog::hasCoreStackOverride());
    }

    public function testReplacedStackSkipsAllBuiltInMiddleware(): void
    {
        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [(self::passthru('custom-only'))()],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );

        $pipeline = new MiddlewarePipeline(Context::getInstance());
        $response = $pipeline->handle(new ServerRequest('GET', 'http://localhost/test'));

        $this->assertSame('custom-only', (string) $response->getBody());
        $this->assertNotContains(ErrorHandlingMiddleware::class, $pipeline->debugStack());
        // The custom middleware, plus the always-appended terminal sentinel — nothing built-in.
        $this->assertCount(2, $pipeline->debugStack());
    }

    public function testReplacedStackDoesNotSpliceInRegisteredMiddleware(): void
    {
        MiddlewareCatalog::register('ShouldNotAppear', self::passthru('should-not-run'));
        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [(self::passthru('custom-only'))()],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );

        $pipeline = new MiddlewarePipeline(Context::getInstance());
        $response = $pipeline->handle(new ServerRequest('GET', 'http://localhost/test'));

        $this->assertSame('custom-only', (string) $response->getBody());
        $this->assertNotContains('ShouldNotAppear', $pipeline->debugStack());
    }

    public function testResetClearsTheOverride(): void
    {
        MiddlewareCatalog::replaceCoreStack(
            fn(Context $c) => [],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        $this->assertFalse(MiddlewareCatalog::hasCoreStackOverride());
    }
}
