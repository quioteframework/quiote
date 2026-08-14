<?php

namespace Quiote\Exception\Rendering;

/**
 * Process-global slot for the "developer" and "safe" exception renderers
 * (the ones {@see \Quiote\Middleware\ErrorHandlingMiddleware} uses when
 * `core.developer_exceptions` is true or false, respectively), mirroring the
 * static, worker-lifetime pattern of {@see \Quiote\Database\DatabaseDriverRegistry} /
 * {@see \Quiote\Middleware\MiddlewareCatalog}.
 *
 * This exists so core never hard-references a concrete renderer class (e.g.
 * {@see WhoopsRenderer}) directly.
 * A plugin contributes a developer renderer via
 * {@see \Quiote\Plugin\PluginRegistrar::developerExceptionRenderer()} and a
 * safe/production renderer via {@see \Quiote\Plugin\PluginRegistrar::safeExceptionRenderer()};
 * first registration wins for each (set-if-absent), matching the override
 * rule every other plugin seam uses. If nothing is registered for the
 * relevant slot, {@see \Quiote\Middleware\ErrorHandlingMiddleware} falls back
 * to {@see SafeRenderer}.
 */
final class ExceptionRendererRegistry
{
    /** @var (callable(): ExceptionRenderer)|null */
    private static $developerRendererFactory = null;

    /** @var (callable(): ExceptionRenderer)|null */
    private static $safeRendererFactory = null;

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

    /**
     * Reports whether a developer-renderer factory has been registered.
     *
     * Answers from the stored factory without invoking it, so asking the question
     * never constructs a renderer.
     */
    public static function hasDeveloperRenderer(): bool
    {
        return self::$developerRendererFactory !== null;
    }

    /** Register the safe/production-renderer factory. Set-if-absent: first caller wins. */
    public static function setSafeRenderer(callable $factory): void
    {
        self::$safeRendererFactory ??= $factory;
    }

    /** @return ExceptionRenderer|null Null if nothing has registered a safe renderer. */
    public static function safeRenderer(): ?ExceptionRenderer
    {
        return self::$safeRendererFactory ? (self::$safeRendererFactory)() : null;
    }

    /**
     * Reports whether a safe-renderer factory has been registered.
     *
     * Answers from the stored factory without invoking it, so asking the question
     * never constructs a renderer.
     */
    public static function hasSafeRenderer(): bool
    {
        return self::$safeRendererFactory !== null;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$developerRendererFactory = null;
        self::$safeRendererFactory = null;
    }
}
