<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Middleware\SessionMiddleware;
use Quiote\Middleware\TelemetryMiddleware;
use Quiote\Middleware\TimingMiddleware;
use Quiote\Middleware\TraceMiddleware;
use Quiote\Middleware\PayloadParsingMiddleware;
use Quiote\Middleware\ContentNegotiationMiddleware;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Middleware\OutputTypeSyncMiddleware;
use Quiote\Security\Csrf\Middleware\CsrfInjectionMiddleware;
use Quiote\Security\Csrf\Middleware\CsrfValidationMiddleware;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Middleware\SlotMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\AssetAggregationMiddleware;
use Quiote\Middleware\FormPopulationMiddleware;
use Quiote\Middleware\ExecutionTimeMiddleware;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Parity check: MiddlewarePipeline now derives the core stack's order from
 * each class's #[Middleware] attribute instead of the old
 * hand-maintained construct() sequence. This asserts the scanned+resolved
 * order still matches that original sequence exactly, and that
 * MiddlewareCatalog::registerAttributed() / register() interact as designed.
 */
class MiddlewareAttributeOrderingTest extends TestCase
{
    public function setUp(): void
    {
        MiddlewareCatalog::initialize([]);
        MiddlewareCatalog::reset();
        // CSRF middleware are no longer in MiddlewarePipeline's own $factories
        // map -- this test builds a pipeline via Context::getInstance() directly,
        // without going through
        // Quiote::bootstrap() (which runs this by default today), so it must
        // register the plugin itself, same as any other plugin-dependent test.
        (new \Quiote\Security\Csrf\CsrfPlugin())->register(new \Quiote\Plugin\PluginRegistrar('quiote/csrf'));
    }

    #[\Override]
    public function tearDown(): void
    {
        MiddlewareCatalog::reset();
    }

    /** Build the pipeline and return its ordered debug stack of labels. */
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

    public function testAttributeScannedOrderMatchesLegacyHardCodedOrder(): void
    {
        $this->assertSame([
            ErrorHandlingMiddleware::class,
            TelemetryMiddleware::class,
            SessionMiddleware::class,
            TimingMiddleware::class,
            TraceMiddleware::class,
            PayloadParsingMiddleware::class,
            ContentNegotiationMiddleware::class,
            RoutingMiddleware::class,
            OutputTypeSyncMiddleware::class,
            CsrfInjectionMiddleware::class,
            CsrfValidationMiddleware::class,
            SecurityMiddleware::class,
            ValidationMiddleware::class,
            SlotMiddleware::class,
            DispatchMiddleware::class,
            AssetAggregationMiddleware::class,
            FormPopulationMiddleware::class,
            ExecutionTimeMiddleware::class,
            '__TERMINAL__',
        ], $this->order());
    }

    /** A no-op pass-through middleware, usable both as a factory and as an autowired attribute class. */
    private static function passthru(): callable
    {
        return static fn(): MiddlewareInterface => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
    }

    public function testAttributedAppMiddlewareIsPlacedByItsOwnAttribute(): void
    {
        MiddlewareCatalog::registerAttributed(AttributeOrderingFixtureMiddleware::class);
        $order = $this->order();

        $this->assertContains(AttributeOrderingFixtureMiddleware::class, $order);
        // Attribute-derived placement is phase + priority + before/after, not
        // "immediately adjacent" like MiddlewareCatalog::register()'s before:/after:
        // hints — the fixture's default priority (0) sorts it after every other
        // before_action entry (all of which set an explicit higher priority), but
        // it must still land after RoutingMiddleware and before DispatchMiddleware.
        $this->assertGreaterThan(
            array_search(RoutingMiddleware::class, $order, true),
            array_search(AttributeOrderingFixtureMiddleware::class, $order, true),
            'the #[Middleware(after: RoutingMiddleware)] attribute keeps it after RoutingMiddleware'
        );
        $this->assertLessThan(
            array_search(DispatchMiddleware::class, $order, true),
            array_search(AttributeOrderingFixtureMiddleware::class, $order, true),
            'phase: before_action keeps it before the action-phase DispatchMiddleware'
        );
    }

    public function testRegisterWinsOverAttributeForTheSameClass(): void
    {
        // The fixture's own #[Middleware] attribute says "after: RoutingMiddleware",
        // but an explicit register() call for the same FQCN must take priority.
        MiddlewareCatalog::registerAttributed(AttributeOrderingFixtureMiddleware::class);
        MiddlewareCatalog::register(
            AttributeOrderingFixtureMiddleware::class,
            self::passthru(),
            before: SessionMiddleware::class
        );

        $order = $this->order();
        $this->assertSame(
            array_search(SessionMiddleware::class, $order, true) - 1,
            array_search(AttributeOrderingFixtureMiddleware::class, $order, true),
            'register() overrides the attribute-derived placement for the same FQCN'
        );
    }
}

#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', after: 'RoutingMiddleware')]
final class AttributeOrderingFixtureMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
