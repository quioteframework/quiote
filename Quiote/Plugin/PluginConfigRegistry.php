<?php
declare(strict_types=1);

namespace Quiote\Plugin;

/**
 * Process-global record of which `plugins.{xml,php,yaml,yml}` file declared
 * each plugin class, mirroring {@see \Quiote\Middleware\Config\MiddlewareConfigRegistry}'s
 * role for `middleware.*` -- kept separately from the flat `plugins` config
 * key itself (see {@see \Quiote\Config\PluginConfigHandler::apply()}), which
 * only ever holds class names and so cannot answer "which file declared this
 * one" on its own.
 *
 * Only covers config-declared plugins. A plugin activated by handing
 * {@see \Quiote\Plugin\PluginManager::add()} an already-built instance has no
 * file to record here -- that is the "programmatic" case `plugins:list`
 * reports when this registry has nothing for a class.
 * @since      4.4.0
 */
final class PluginConfigRegistry
{
    /** @var array<string, string> plugin class name => the sourceRef of the file that first declared it */
    private static array $sourceRefs = [];

    /**
     * Records $sourceRef for each class not already recorded.
     *
     * First occurrence wins, matching {@see \Quiote\Config\PluginConfigHandler::merge()}'s own
     * "first occurrence across files wins" rule for the `plugins` config key itself: the app's own
     * `plugins.*` is applied before any module's, so a class the app already declared keeps the
     * app's file as its recorded source even if a module also lists it.
     *
     * @param list<string> $classes
     */
    public static function contribute(array $classes, string $sourceRef): void
    {
        foreach ($classes as $class) {
            self::$sourceRefs[$class] ??= $sourceRef;
        }
    }

    /** The file that declared $class, or null if it was never seen here (activated programmatically instead). */
    public static function sourceRefFor(string $class): ?string
    {
        return self::$sourceRefs[$class] ?? null;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$sourceRefs = [];
    }
}
