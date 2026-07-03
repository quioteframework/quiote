<?php

namespace Quiote\Middleware;

use Quiote\Context;

/**
 * MiddlewareCatalog stores enable/disable flags for middleware FQCNs as parsed
 * from <middleware_config> so the runtime pipeline builder can cheaply skip
 * optional middlewares. Unknown classes default to enabled (backwards compatible).
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

    /** @var (callable(Context): list<\Psr\Http\Server\MiddlewareInterface>)|null */
    private static $coreStackFactory = null;

    /**
     * FQCNs of app middleware to include in `#[Middleware]` attribute
     * scanning, in addition to the framework's own core classes. Populated
     * via {@see registerAttributed()}.
     * @var array<string,true>
     */
    private static array $attributedCandidates = [];

    /** Initialize the catalog (idempotent overwrite). */
    public static function initialize(array $map): void
    {
        self::$enabledMap = $map;
    }

    /** Whether a middleware is enabled; unknown => true. */
    public static function isEnabled(string $fqcn): bool
    {
        return self::$enabledMap[$fqcn] ?? true;
    }

    /** Whether $fqcn has an explicit enabled/disabled entry from <middleware_config>. */
    public static function hasOverride(string $fqcn): bool
    {
        return array_key_exists($fqcn, self::$enabledMap);
    }

    /**
     * Register an app middleware class as a candidate for `#[Middleware]`
     * attribute scanning. Unlike {@see register()}, no factory or explicit
     * before/after/priority is needed here — the class must carry a
     * `#[Middleware(...)]` attribute describing its own placement, and it is
     * resolved through the DI container when the pipeline builds. If the
     * same FQCN is also passed to {@see register()}, register() wins (see
     * docs/MIDDLEWARE_ATTRIBUTE_REGISTRATION_PLAN.md) and this candidate
     * entry is ignored.
     */
    public static function registerAttributed(string $fqcn): void
    {
        self::$attributedCandidates[$fqcn] = true;
    }

    /** @return string[] FQCNs registered via {@see registerAttributed()}. */
    public static function getAttributedCandidates(): array
    {
        return array_keys(self::$attributedCandidates);
    }

    /** Raw map mainly for tests. */
    public static function all(): array
    {
        return self::$enabledMap;
    }

    /**
     * Register a custom middleware to be inserted into the pipeline.
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

    /** @return array<string,array{fqcn: string, factory: callable, after: ?string, before: ?string, priority: int}> */
    public static function getRegistered(): array
    {
        return self::$registered;
    }

    /** Clear all registered middleware (mainly for tests). */
    public static function reset(): void
    {
        self::$registered = [];
        self::$coreStackFactory = null;
        self::$attributedCandidates = [];
    }

    /**
     * Escape hatch: replace Quiote's ENTIRE built-in middleware stack — including
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
        self::$coreStackFactory = $factory;
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
        return (self::$coreStackFactory)($context);
    }
}
