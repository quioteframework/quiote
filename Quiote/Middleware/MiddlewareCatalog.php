<?php

namespace Quiote\Middleware;

/**
 * MiddlewareCatalog stores enable/disable flags for middleware FQCNs as parsed
 * from <middleware_config> so the runtime pipeline builder can cheaply skip
 * optional middlewares. Unknown classes default to enabled (backwards compatible).
 */
class MiddlewareCatalog
{
    /** @var array<string,bool> */
    private static array $enabledMap = [];

    /**
     * @var array<string,array{fqcn: string, factory: callable, after: ?string, before: ?string, priority: int}>
     */
    private static array $registered = [];

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
    }
}
