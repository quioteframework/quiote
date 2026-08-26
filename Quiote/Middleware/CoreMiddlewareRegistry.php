<?php

namespace Quiote\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Context;

/**
 * The single declaration of the middleware the framework ships.
 *
 * One entry per middleware, carrying both of the things the framework needs to know about it:
 * how to construct it, and whether config may silently reorder or disable it. Deriving both
 * answers from the same list is the point -- a middleware added to a construction map but
 * forgotten in a separate guard list is unguarded, which is how a `<use>` entry in any
 * installed module's `middleware.*` was once able to switch CSRF validation off with no
 * acknowledgement.
 *
 * Ordering is not declared here. It comes from each class's own `#[Middleware]` attribute
 * (phase, before/after, priority) and is resolved by {@see Compiler\MiddlewareOrderResolver},
 * so a middleware states its own placement next to its implementation.
 *
 * @since      3.2.0
 */
final class CoreMiddlewareRegistry
{
    /**
     * First-party middleware delivered by a plugin rather than built here.
     *
     * The CSRF middleware ship with {@see \Quiote\Security\Csrf\CsrfPlugin}, which core
     * enables by default, so they are constructed by that plugin's attributed factory. They
     * are named as plain strings because the guard only ever compares them, and
     * {@see Config\MiddlewareConfigRegistry::assertValidClass()} has already established that
     * a named class exists before any comparison happens -- so listing one from a package that
     * is not installed costs nothing.
     * @var list<string>
     */
    private const array PLUGIN_PROVIDED = [
        'Quiote\\Security\\Csrf\\Middleware\\CsrfValidationMiddleware',
        'Quiote\\Security\\Csrf\\Middleware\\CsrfInjectionMiddleware',
    ];

    /**
     * The middleware core builds itself, in declaration order.
     *
     * The one list the framework derives every answer from. {@see factories()} must supply a
     * closure for exactly these classes and is checked against this list on every build, so
     * adding a middleware here without a factory -- or a factory without an entry here -- fails
     * loudly at build time instead of leaving it unconstructable or unguarded.
     * @var list<class-string<\Psr\Http\Server\MiddlewareInterface>>
     */
    public const array CORE = [
        ErrorHandlingMiddleware::class,
        SessionMiddleware::class,
        TelemetryMiddleware::class,
        TimingMiddleware::class,
        TraceMiddleware::class,
        PayloadParsingMiddleware::class,
        ContentNegotiationMiddleware::class,
        RoutingMiddleware::class,
        OutputTypeSyncMiddleware::class,
        SecurityMiddleware::class,
        ValidationMiddleware::class,
        SlotMiddleware::class,
        DispatchMiddleware::class,
        AssetAggregationMiddleware::class,
        FormPopulationMiddleware::class,
        ExecutionTimeMiddleware::class,
        StealthMiddleware::class,
    ];

    /**
     * Construction closures for the middleware core builds itself, keyed by class name.
     *
     * Each factory receives the Context whose pipeline is being built, so a middleware needing
     * a per-context collaborator gets the right one without capturing anything at declaration
     * time. A zero-argument factory simply ignores it, as any PHP callable does.
     *
     * @return     array<class-string<\Psr\Http\Server\MiddlewareInterface>, callable(Context): \Psr\Http\Server\MiddlewareInterface>
     */
    public static function factories(Context $context): array
    {
        $controller = $context->getContainer()->get(\Quiote\Controller\Controller::class);
        $routing = $context->getContainer()->get(\Quiote\Routing\Routing::class);

        $factories = [
            ErrorHandlingMiddleware::class => static fn(): ErrorHandlingMiddleware => new ErrorHandlingMiddleware(
                static function (\Throwable $e, ServerRequestInterface $r): void {
                    $first = $e->getFile() . ':' . $e->getLine();
                    $snippet = substr(str_replace("\n", ' | ', $e->getTraceAsString()), 0, 500);
                    \Quiote\Logging\Log::create('Quiote.Middleware.MiddlewarePipeline')->error(
                        '[MiddlewarePipeline] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $first . ' trace=' . $snippet
                    );
                    // Backstop: TelemetryMiddleware records and ends the root span on its own
                    // way out, and it sits inside this one, so by here Trace::current() is
                    // normally a no-op. This matters only when TelemetryMiddleware never ran
                    // (a stack replaced via replaceCoreStack()) while a span is still active.
                    \Quiote\Telemetry\Trace::current()->recordException($e)->setStatusError($e->getMessage());
                },
                $context,
            ),
            SessionMiddleware::class => static fn(): SessionMiddleware => new SessionMiddleware($controller),
            TelemetryMiddleware::class => static fn(): TelemetryMiddleware => new TelemetryMiddleware(),
            TimingMiddleware::class => static fn(): TimingMiddleware => new TimingMiddleware(
                \Quiote\Config\Config::getBool('middleware.timing.emit_header', false)
            ),
            TraceMiddleware::class => static fn(): TraceMiddleware => new TraceMiddleware(
                \Quiote\Config\Config::getBool('middleware.trace.emit_header', false),
                \Quiote\Config\Config::getString('middleware.trace.header_name', 'X-Quiote-Trace')
            ),
            PayloadParsingMiddleware::class => static fn(): PayloadParsingMiddleware => new PayloadParsingMiddleware(),
            ContentNegotiationMiddleware::class => static fn(): ContentNegotiationMiddleware => new ContentNegotiationMiddleware(),
            RoutingMiddleware::class => static fn(): RoutingMiddleware => new RoutingMiddleware($routing, $controller),
            OutputTypeSyncMiddleware::class => static fn(): OutputTypeSyncMiddleware => new OutputTypeSyncMiddleware($controller),
            SecurityMiddleware::class => static fn(): SecurityMiddleware => new SecurityMiddleware($controller),
            ValidationMiddleware::class => static fn(): ValidationMiddleware => new ValidationMiddleware($controller),
            SlotMiddleware::class => static fn(): SlotMiddleware => new SlotMiddleware($context),
            DispatchMiddleware::class => static fn(): DispatchMiddleware => new DispatchMiddleware($controller),
            AssetAggregationMiddleware::class => static fn(): AssetAggregationMiddleware => new AssetAggregationMiddleware(),
            FormPopulationMiddleware::class => static fn(): FormPopulationMiddleware => new FormPopulationMiddleware($controller),
            ExecutionTimeMiddleware::class => static fn(): ExecutionTimeMiddleware => new ExecutionTimeMiddleware(),
            StealthMiddleware::class => static fn(): StealthMiddleware => new StealthMiddleware(
                \Quiote\Config\Config::getBool('core.stealth_mode', false),
                \Quiote\Config\Config::getStringList('core.stealth_additional_headers', ['X-Powered-By'])
            ),
        ];

        $missing = array_diff(self::CORE, array_keys($factories));
        $extra = array_diff(array_keys($factories), self::CORE);
        if ($missing !== [] || $extra !== []) {
            throw new \Quiote\Exception\QuioteException(sprintf(
                'CoreMiddlewareRegistry is inconsistent: %s declared in CORE without a factory, %s with a factory but not declared.',
                $missing === [] ? 'none' : implode(', ', $missing),
                $extra === [] ? 'none' : implode(', ', $extra),
            ));
        }

        return $factories;
    }

    /**
     * Every class {@see Config\MiddlewareConfigRegistry} guards against silent config-driven
     * reordering or disabling: what core builds, plus the first-party middleware a plugin
     * delivers. Needs no Context, so a guard can consult it from anywhere.
     * @return     list<string>
     */
    public static function guardedClasses(): array
    {
        return array_merge(self::CORE, self::PLUGIN_PROVIDED);
    }

    /**
     * First-party middleware delivered by a plugin rather than constructed by core.
     * @return     list<string>
     */
    public static function pluginProvidedClasses(): array
    {
        return self::PLUGIN_PROVIDED;
    }
}
