<?php

namespace Quiote\Middleware;

use Quiote\Context;

/**
 * MiddlewareCatalog stores enable/disable flags for middleware FQCNs, settable
 * programmatically via {@see initialize()} (tests, app bootstrap code), so the
 * runtime pipeline builder can cheaply skip optional middlewares. Unknown
 * classes default to enabled (backwards compatible). Declarative
 * `middleware.xml` enable/disable is a separate, higher-level mechanism (see
 * {@see \Quiote\Middleware\Config\MiddlewareConfigRegistry}) that merges into
 * a {@see \Quiote\Middleware\Compiler\MiddlewareDefinition}'s own `enabled`
 * field rather than this map -- this map still wins when both are present
 * (see the precedence check in {@see MiddlewarePipeline::doBuild()}).
 *
 * Middleware contributed by plugins (through {@see \Quiote\Plugin\PluginRegistrar})
 * is kept apart from middleware the application or a test registers directly.
 * Plugins register once per process, so {@see reset()} leaves their contributions
 * in place; only {@see resetPluginRegistrations()}, which
 * {@see \Quiote\Plugin\PluginManager::reset()} calls along with the rest of the
 * plugin state, removes them. A test that registers plugin contributions itself
 * puts the catalog back with {@see snapshot()} and {@see restore()}.
 */
class MiddlewareCatalog
{
    /**
     * The exact string {@see replaceCoreStack()} requires as its second argument.
     * Deliberately long and explicit — this must be typed (or copy-pasted with
     * intent) by someone who has read the docblock, not something that can be
     * flipped on by a stray boolean or a config typo.
     */
    public const REPLACE_CORE_STACK_ACKNOWLEDGEMENT =
        'I_UNDERSTAND_THIS_DISCARDS_ERROR_HANDLING_SESSIONS_CSRF_SECURITY_AND_ROUTING';

    /** @var array<string,bool> */
    private static array $enabledMap = [];

    /**
     * @var array<string,array{fqcn: string, factory: callable, after: ?string, before: ?string, priority: int}>
     */
    private static array $registered = [];

    /**
     * {@see register()}-style entries contributed by plugins, kept out of {@see reset()}'s reach.
     * @var array<string,array{fqcn: string, factory: callable, after: ?string, before: ?string, priority: int}>
     */
    private static array $pluginRegistered = [];

    /** @var (\Closure(Context): list<\Psr\Http\Server\MiddlewareInterface>)|null */
    private static ?\Closure $coreStackFactory = null;

    /**
     * FQCNs of application middleware to include in `#[Middleware]` attribute
     * scanning, in addition to the framework's own core classes. Populated
     * via {@see registerAttributed()}. Value is `true` when the class is
     * expected to be constructible by the DI container unassisted (the common
     * case), or a factory callable when it needs a hand-built factory (e.g. a
     * middleware that needs the building pipeline's {@see Context} —
     * see {@see attributedFactory()}) while still being *ordered* by its own
     * `#[Middleware]` attribute like any other attribute-scanned class.
     * @var array<string,true|callable(Context): \Psr\Http\Server\MiddlewareInterface>
     */
    private static array $attributedCandidates = [];

    /**
     * {@see registerAttributed()}-style candidates contributed by plugins, kept out of
     * {@see reset()}'s reach.
     * @var array<string,true|callable(Context): \Psr\Http\Server\MiddlewareInterface>
     */
    private static array $pluginAttributedCandidates = [];

    /**
     * Initialize the catalog (idempotent overwrite).
     * @param array<string,bool> $map
     */
    public static function initialize(array $map): void
    {
        self::$enabledMap = $map;
    }

    /** Whether a middleware is enabled; unknown => true. */
    public static function isEnabled(string $fqcn): bool
    {
        return self::$enabledMap[$fqcn] ?? true;
    }

    /** Whether $fqcn has an explicit enabled/disabled entry via {@see initialize()}. */
    public static function hasOverride(string $fqcn): bool
    {
        return array_key_exists($fqcn, self::$enabledMap);
    }

    /**
     * Register an application middleware class as a candidate for `#[Middleware]`
     * attribute scanning. No explicit before/after/priority is needed here —
     * the class must carry a `#[Middleware(...)]` attribute describing its own
     * placement. If the same FQCN is also passed to {@see register()},
     * register() wins and this candidate entry is ignored.
     *
     * By default the class is resolved through the DI container when the
     * pipeline builds (`$container->get($fqcn)`), which only works if every
     * constructor dependency is either type-hinted and container-resolvable
     * or has a default. A middleware that needs the currently-building
     * pipeline's {@see Context} itself (e.g. to pull the context's own
     * `Controller` instance rather than risk autowiring an unrelated one)
     * supplies `$factory` instead; it is
     * called with that `Context` when the pipeline builds, while ordering
     * still comes from the class's own attribute, same as the DI-resolved case.
     *
     * @param ?callable(Context): \Psr\Http\Server\MiddlewareInterface $factory
     */
    public static function registerAttributed(string $fqcn, ?callable $factory = null): void
    {
        self::$attributedCandidates[$fqcn] = $factory ?? true;
    }

    /**
     * Plugin counterpart of {@see registerAttributed()}, called by
     * {@see \Quiote\Plugin\PluginRegistrar::attributedMiddleware()}. The candidate
     * survives {@see reset()}; an application's own {@see registerAttributed()} of the
     * same FQCN takes precedence over it.
     *
     * @param ?callable(Context): \Psr\Http\Server\MiddlewareInterface $factory
     */
    public static function registerAttributedFromPlugin(string $fqcn, ?callable $factory = null): void
    {
        self::$pluginAttributedCandidates[$fqcn] = $factory ?? true;
    }

    /** @return list<string> FQCNs registered via {@see registerAttributed()} or {@see registerAttributedFromPlugin()}. */
    public static function getAttributedCandidates(): array
    {
        return array_keys(self::attributedEntries());
    }

    /** @return (callable(Context): \Psr\Http\Server\MiddlewareInterface)|null The custom factory for $fqcn, if one was supplied to {@see registerAttributed()} or {@see registerAttributedFromPlugin()}. */
    public static function attributedFactory(string $fqcn): ?callable
    {
        $entry = self::attributedEntries()[$fqcn] ?? null;
        return is_callable($entry) ? $entry : null;
    }

    /**
     * Plugin candidates first, in registration order, with the application's own
     * entries replacing a plugin's entry for the same FQCN in place.
     *
     * @return array<string,true|callable(Context): \Psr\Http\Server\MiddlewareInterface>
     */
    private static function attributedEntries(): array
    {
        return array_replace(self::$pluginAttributedCandidates, self::$attributedCandidates);
    }

    /**
     * Raw map mainly for tests.
     * @return array<string,bool>
     */
    public static function all(): array
    {
        return self::$enabledMap;
    }

    /**
     * Register a custom middleware to be inserted into the pipeline.
     *
     * With no `$after`/`$before` hint, the middleware lands right after
     * {@see \Quiote\Middleware\ValidationMiddleware} by default, so it always
     * receives validated parameters. Opt into running earlier (e.g. before
     * {@see \Quiote\Middleware\SecurityMiddleware}) only if the middleware
     * genuinely needs to see the request before validation/security run —
     * that should be the exception, not the default.
     *
     * @param string        $fqcn     Fully-qualified class name (used as key + debug label)
     * @param callable      $factory  Factory that returns a PSR-15 MiddlewareInterface
     * @param string|null   $after    Insert after this middleware FQCN in the stack
     * @param string|null   $before   Insert before this middleware FQCN in the stack
     * @param int           $priority Ordering among registered middleware at the same position (lower = earlier)
     */
    public static function register(string $fqcn, callable $factory, ?string $after = null, ?string $before = null, int $priority = 0): void
    {
        self::$registered[$fqcn] = [
            'fqcn'     => $fqcn,
            'factory'  => $factory,
            'after'    => $after,
            'before'   => $before,
            'priority' => $priority,
        ];
    }

    /**
     * Plugin counterpart of {@see register()}, called by
     * {@see \Quiote\Plugin\PluginRegistrar::middleware()}. The entry survives
     * {@see reset()}; an application's own {@see register()} of the same FQCN takes
     * precedence over it.
     */
    public static function registerFromPlugin(string $fqcn, callable $factory, ?string $after = null, ?string $before = null, int $priority = 0): void
    {
        self::$pluginRegistered[$fqcn] = [
            'fqcn'     => $fqcn,
            'factory'  => $factory,
            'after'    => $after,
            'before'   => $before,
            'priority' => $priority,
        ];
    }

    /**
     * Every registered middleware: plugin entries first, in registration order, with
     * the application's own {@see register()} entries replacing a plugin's entry for
     * the same FQCN in place.
     *
     * @return array<string,array{fqcn: string, factory: callable, after: ?string, before: ?string, priority: int}>
     */
    public static function getRegistered(): array
    {
        return array_replace(self::$pluginRegistered, self::$registered);
    }

    /**
     * Clears what the application or a test set up directly: the enable map,
     * {@see register()} and {@see registerAttributed()} entries, and a
     * {@see replaceCoreStack()} override. Plugin contributions stay, because
     * plugins register once per process and nothing would put them back; clear
     * those through {@see \Quiote\Plugin\PluginManager::reset()}.
     */
    public static function reset(): void
    {
        self::$enabledMap = [];
        self::$registered = [];
        self::$coreStackFactory = null;
        self::$attributedCandidates = [];
    }

    /**
     * Clears the middleware plugins contributed through
     * {@see registerFromPlugin()} and {@see registerAttributedFromPlugin()}.
     * Called by {@see \Quiote\Plugin\PluginManager::reset()}, which also clears
     * the booted flag so the plugins can register again.
     */
    public static function resetPluginRegistrations(): void
    {
        self::$pluginRegistered = [];
        self::$pluginAttributedCandidates = [];
    }

    /** Captures the whole catalog, plugin contributions included, for {@see restore()}. */
    public static function snapshot(): MiddlewareCatalogSnapshot
    {
        return new MiddlewareCatalogSnapshot(
            self::$enabledMap,
            self::$registered,
            self::$pluginRegistered,
            self::$attributedCandidates,
            self::$pluginAttributedCandidates,
            self::$coreStackFactory,
        );
    }

    /** Puts the catalog back exactly as {@see snapshot()} captured it. */
    public static function restore(MiddlewareCatalogSnapshot $snapshot): void
    {
        self::$enabledMap = $snapshot->enabledMap;
        self::$registered = $snapshot->registered;
        self::$pluginRegistered = $snapshot->pluginRegistered;
        self::$attributedCandidates = $snapshot->attributedCandidates;
        self::$pluginAttributedCandidates = $snapshot->pluginAttributedCandidates;
        self::$coreStackFactory = $snapshot->coreStackFactory;
    }

    /**
     * Replaces Quiote's ENTIRE built-in middleware stack — including
     * ErrorHandlingMiddleware, SessionMiddleware, CSRF, SecurityMiddleware, and
     * RoutingMiddleware — with one supplied by the application.
     *
     * This is not a configuration knob for the normal case. {@see register()}
     * covers "add my middleware at this point" for the overwhelming majority of
     * customization needs, and keeps every framework default intact around it.
     * Reach for this ONLY if you are building something that genuinely cannot
     * run inside Quiote's own request lifecycle at all — once active, Quiote
     * guarantees nothing about error handling, sessions, CSRF, or security for
     * that context: you own all of it.
     *
     * The $acknowledgement argument must equal
     * {@see REPLACE_CORE_STACK_ACKNOWLEDGEMENT} exactly, so this can't be
     * triggered by a stray boolean, a config typo, or copy-pasted example code
     * without also reading this docblock.
     *
     * @param callable(Context): list<\Psr\Http\Server\MiddlewareInterface> $factory
     *        Returns the complete ordered stack. Quiote still appends a terminal
     *        sentinel after it so the pipeline always yields a response instead
     *        of silently returning null — that's a PSR-15 contract requirement,
     *        not an opinion about what your stack should contain. Externally
     *        {@see register()}-ed middleware is NOT spliced in when this is
     *        active; if you want it, add it inside $factory yourself.
     * @throws \InvalidArgumentException if $acknowledgement doesn't match exactly.
     */
    public static function replaceCoreStack(callable $factory, string $acknowledgement): void
    {
        if ($acknowledgement !== self::REPLACE_CORE_STACK_ACKNOWLEDGEMENT) {
            throw new \InvalidArgumentException(
                'MiddlewareCatalog::replaceCoreStack() refused: the acknowledgement string did not '
                . 'match exactly. This method discards Quiote\'s entire default middleware stack '
                . '(error handling, sessions, CSRF, security, routing) — read its docblock, then pass '
                . 'MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT verbatim as the second argument.'
            );
        }
        self::$coreStackFactory = $factory(...);
    }

    /** Whether an app has installed a full core-stack replacement via {@see replaceCoreStack()}. */
    public static function hasCoreStackOverride(): bool
    {
        return self::$coreStackFactory !== null;
    }

    /**
     * Invoke the app-supplied replacement factory.
     * @return list<\Psr\Http\Server\MiddlewareInterface>
     */
    public static function buildCoreStack(Context $context): array
    {
        $factory = self::$coreStackFactory;
        if ($factory === null) {
            throw new \Quiote\Exception\QuioteException(
                'MiddlewareCatalog::buildCoreStack() called without a core stack replacement '
                . 'installed via replaceCoreStack(); check hasCoreStackOverride() first.'
            );
        }

        return $factory($context);
    }
}
