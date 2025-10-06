<?php
namespace Agavi\Middleware;

/**
 * MiddlewareCatalog stores enable/disable flags for middleware FQCNs as parsed
 * from <middleware_config> so the runtime pipeline builder can cheaply skip
 * optional middlewares. Unknown classes default to enabled (backwards compatible).
 */
class MiddlewareCatalog
{
    /** @var array<string,bool> */
    private static array $enabledMap = [];

    /** Initialize the catalog (idempotent overwrite). */
    public static function initialize(array $map): void
    { self::$enabledMap = $map; }

    /** Whether a middleware is enabled; unknown => true. */
    public static function isEnabled(string $fqcn): bool
    { return self::$enabledMap[$fqcn] ?? true; }

    /** Raw map mainly for tests. */
    public static function all(): array
    { return self::$enabledMap; }
}

?>