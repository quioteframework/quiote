<?php

namespace Quiote\Logging;

use Quiote\Logging\Sink\SinkInterface;

/**
 * Process-global store of logging configuration: the default minimum level, the
 * per-category minimum levels, and the registered sinks.
 * Deliberately free of any dependency on Config / the context / bootstrap,
 * so it can be configured in index.php BEFORE Kernel::run() and is usable
 * during bootstrap itself. Configuration is set once at worker startup and is
 * immutable for the worker lifetime (the only per-request logging state lives in
 * {@see LogContext}). {@see Log} is the public facade over this store.
 */
final class LogRegistry
{
    private static Level $defaultLevel = Level::Info;

    /** @var array<string,Level> category-prefix => minimum level */
    private static array $categoryLevels = [];

    /** @var array<string,Level> memoized resolved threshold per exact category */
    private static array $resolved = [];

    /** @var list<SinkInterface> */
    private static array $sinks = [];

    /**
     * Sets the minimum level applied to categories with no matching prefix rule.
     *
     * Discards the resolved-threshold memo, so categories that had fallen through to
     * the previous default are re-resolved on their next log call.
     */
    public static function setDefaultLevel(Level $level): void
    {
        self::$defaultLevel = $level;
        self::$resolved = [];
    }

    /**
     * Sets the minimum level for one dotted category prefix, replacing any level
     * already stored for that exact prefix.
     *
     * Discards the resolved-threshold memo so existing categories re-resolve against
     * the new rule; see {@see resolveLevel()} for how competing prefixes are ranked.
     */
    public static function setLevel(string $categoryPrefix, Level $level): void
    {
        self::$categoryLevels[$categoryPrefix] = $level;
        self::$resolved = [];
    }

    /**
     * @param array<string,Level> $map category-prefix => Level
     */
    public static function setLevels(array $map): void
    {
        foreach ($map as $prefix => $level) {
            self::$categoryLevels[$prefix] = $level;
        }
        self::$resolved = [];
    }

    /**
     * Appends a sink to the process-global sink list, in registration order.
     *
     * No de-duplication is performed: registering the same sink twice makes it
     * receive every record twice.
     */
    public static function addSink(SinkInterface $sink): void
    {
        self::$sinks[] = $sink;
    }

    /** @return list<SinkInterface> */
    public static function sinks(): array
    {
        return self::$sinks;
    }

    /**
     * Reports whether any sink is registered.
     *
     * False means every record is discarded regardless of level, so callers can skip
     * building a record at all.
     */
    public static function hasSinks(): bool
    {
        return self::$sinks !== [];
    }

    /**
     * Resolve the minimum level for a category: the level of the longest
     * configured prefix that matches on a dot boundary, else the default.
     * Memoized per exact category string.
     */
    public static function resolveLevel(string $category): Level
    {
        if (isset(self::$resolved[$category])) {
            return self::$resolved[$category];
        }
        $best = null;
        $bestLen = -1;
        foreach (self::$categoryLevels as $prefix => $level) {
            if ($category === $prefix || str_starts_with($category, $prefix . '.')) {
                $len = strlen($prefix);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $level;
                }
            }
        }
        return self::$resolved[$category] = $best ?? self::$defaultLevel;
    }

    /**
     * Reset all configuration and drop sinks. For test isolation and
     * reconfiguration; not used on the request path.
     */
    public static function reset(): void
    {
        self::$defaultLevel = Level::Info;
        self::$categoryLevels = [];
        self::$resolved = [];
        self::$sinks = [];
    }
}
