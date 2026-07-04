<?php

namespace Quiote\Exception\Rendering;

/**
 * Process-global slot for the "developer" exception renderer (the one
 * {@see \Quiote\Middleware\ErrorHandlingMiddleware} uses when
 * `core.developer_exceptions` is true), mirroring the static, worker-lifetime
 * pattern of {@see \Quiote\Database\DatabaseDriverRegistry} /
 * {@see \Quiote\Middleware\MiddlewareCatalog}.
 *
 * This exists so core never hard-references a concrete renderer class (e.g.
 * {@see WhoopsRenderer}) directly — see docs/PLUGIN_EXTRACTION_PLAN.md §2.4.
 * A plugin contributes a renderer via
 * {@see \Quiote\Plugin\PluginRegistrar::developerExceptionRenderer()}; first
 * registration wins (set-if-absent), matching the override rule every other
 * plugin seam uses. If nothing is registered — or `core.developer_exceptions`
 * is off — {@see \Quiote\Middleware\ErrorHandlingMiddleware} falls back to
 * {@see SafeRenderer}.
 */
final class ExceptionRendererRegistry
{
    /** @var (callable(): ExceptionRenderer)|null */
    private static $developerRendererFactory = null;

    private function __construct() {}

    /** Register the developer-renderer factory. Set-if-absent: first caller wins. */
    public static function setDeveloperRenderer(callable $factory): void
    {
        self::$developerRendererFactory ??= $factory;
    }

    /** @return ExceptionRenderer|null Null if nothing has registered a developer renderer. */
    public static function developerRenderer(): ?ExceptionRenderer
    {
        return self::$developerRendererFactory ? (self::$developerRendererFactory)() : null;
    }

    public static function hasDeveloperRenderer(): bool
    {
        return self::$developerRendererFactory !== null;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$developerRendererFactory = null;
    }
}
