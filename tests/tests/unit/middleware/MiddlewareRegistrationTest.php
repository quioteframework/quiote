<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\SessionMiddleware;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Middleware\ValidationMiddleware;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Verifies that applications can inject custom middleware at predefined
 * positions in the pipeline via MiddlewareCatalog::register(..., before:/after:),
 * and that MiddlewarePipeline splices them in at the right place — including the
 * chained case where a registered middleware is positioned relative to ANOTHER
 * registered middleware (as jakamo's MiddlewareBootstrap does).
 */
class MiddlewareRegistrationTest extends TestCase
{
    public function setUp(): void
    {
        MiddlewareCatalog::initialize([]); // all enabled
        MiddlewareCatalog::reset();        // no registrations leaking in
        MiddlewareConfigRegistry::reset();
    }

    #[\Override]
    public function tearDown(): void
    {
        // Registrations are process-global static state; clear them so they do
        // not pollute other pipeline tests.
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
    }

    /** A no-op pass-through middleware factory (label is what shows in debugStack). */
    private static function passthru(): callable
    {
        return static fn(): MiddlewareInterface => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
    }

    /** Build the pipeline and return its ordered debug stack of labels.
     *
     * @return list<string>
     */
    private function order(): array
    {
        $pipeline = new MiddlewarePipeline(Context::getInstance());
        try {
            $pipeline->handle(new ServerRequest('GET', 'http://localhost/test'));
        } catch (\Throwable) {
            // debugStack is populated during build, before the stack runs.
        }
        return $pipeline->debugStack();
    }

    public function testRegisterBeforeInsertsImmediatelyBeforeTarget(): void
    {
        MiddlewareCatalog::register('CustomBefore', self::passthru(), before: SessionMiddleware::class);
        $order = $this->order();
        $this->assertContains('CustomBefore', $order);
        $sessionIndex = array_search(SessionMiddleware::class, $order, true);
        $this->assertNotFalse($sessionIndex, 'SessionMiddleware must be present in the pipeline order');
        $this->assertSame(
            $sessionIndex - 1,
            array_search('CustomBefore', $order, true),
            'before: places the middleware immediately before its target'
        );
    }

    public function testRegisterAfterInsertsImmediatelyAfterTarget(): void
    {
        MiddlewareCatalog::register('CustomAfter', self::passthru(), after: RoutingMiddleware::class);
        $order = $this->order();
        $this->assertContains('CustomAfter', $order);
        $routingIndex = array_search(RoutingMiddleware::class, $order, true);
        $this->assertNotFalse($routingIndex, 'RoutingMiddleware must be present in the pipeline order');
        $this->assertSame(
            $routingIndex + 1,
            array_search('CustomAfter', $order, true),
            'after: places the middleware immediately after its target'
        );
    }

    public function testChainedAfterReferencingAnotherRegisteredMiddleware(): void
    {
        // Mirrors jakamo: JwtAuth after Routing, ApiAuth after JwtAuth, ApiLog after ApiAuth.
        MiddlewareCatalog::register('Jwt', self::passthru(), after: RoutingMiddleware::class);
        MiddlewareCatalog::register('ApiAuth', self::passthru(), after: 'Jwt');
        MiddlewareCatalog::register('ApiLog', self::passthru(), after: 'ApiAuth');

        $order = $this->order();
        $routing = array_search(RoutingMiddleware::class, $order, true);
        $jwt = array_search('Jwt', $order, true);
        $apiAuth = array_search('ApiAuth', $order, true);
        $apiLog = array_search('ApiLog', $order, true);

        $this->assertNotFalse($routing);
        $this->assertNotFalse($jwt);
        $this->assertNotFalse($apiAuth);
        $this->assertNotFalse($apiLog);
        // Strictly ordered: Routing < Jwt < ApiAuth < ApiLog, each immediately after the last.
        $this->assertSame($routing + 1, $jwt);
        $this->assertSame($jwt + 1, $apiAuth);
        $this->assertSame($apiAuth + 1, $apiLog);
    }

    public function testNoHintsFallsBackToAfterValidation(): void
    {
        MiddlewareCatalog::register('CustomDefault', self::passthru());
        $order = $this->order();
        $this->assertSame(
            array_search(ValidationMiddleware::class, $order, true) + 1,
            array_search('CustomDefault', $order, true),
            'no before/after hint falls back to just after ValidationMiddleware'
        );
    }

    public function testDisabledRegisteredMiddlewareIsSkipped(): void
    {
        MiddlewareCatalog::initialize(['CustomOff' => false]);
        MiddlewareCatalog::register('CustomOff', self::passthru(), after: RoutingMiddleware::class);
        $this->assertNotContains('CustomOff', $this->order());
    }
}
