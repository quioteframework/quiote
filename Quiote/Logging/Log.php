<?php

namespace Quiote\Logging;

use Quiote\Logging\Sink\SinkInterface;

/**
 * Static facade for the logging subsystem: configuration (called in index.php
 * before Kernel::run()) and logger acquisition (used everywhere else).
 * Configuration example (index.php):
 *   use Quiote\Logging\{Log, Level};
 *   use Quiote\Logging\Sink\JsonStdoutSink;
 *   Log::setDefaultLevel(Level::Info);
 *   Log::setLevels(['Quiote' => Level::Warning, 'App.Orders' => Level::Debug]);
 *   Log::addSink(new JsonStdoutSink(Level::Info));
 * Acquisition:
 *   $log = Log::for($this);          // category from the class FQCN (dot-normalized)
 *   $log = Log::create('Quiote.Routing');
 * All calls delegate to {@see LogRegistry}, so the DI-registered
 * {@see LoggerFactory} and this facade share one configuration.
 */
final class Log
{
    private function __construct() {}

    /**
     * Category => CategoryLogger. A logger's only mutable state is its cached
     * threshold, which is itself only valid for as long as the logging config
     * is (i.e. the worker's lifetime), so instances are safe to hand out
     * repeatedly instead of reallocating one per call site per request.
     * @var array<string,CategoryLogger>
     */
    private static array $cache = [];

    // --- configuration -----------------------------------------------------

    /**
     * Sets the minimum level for every category without a more specific rule.
     *
     * Delegates to {@see LogRegistry}, whose memoized per-category thresholds are
     * invalidated, so loggers already handed out pick the change up. Configuration
     * belongs at worker startup (index.php, before `Kernel::run()`), not per request.
     */
    public static function setDefaultLevel(Level $level): void
    {
        LogRegistry::setDefaultLevel($level);
    }

    /**
     * Sets the minimum level for one dotted category prefix, e.g. `Quiote.Routing`.
     *
     * A prefix matches a category exactly or on a dot boundary, and the longest
     * matching prefix wins over both shorter ones and the default level. Delegates
     * to {@see LogRegistry}, invalidating its resolved-threshold memo.
     */
    public static function setLevel(string $categoryPrefix, Level $level): void
    {
        LogRegistry::setLevel($categoryPrefix, $level);
    }

    /**
     * @param array<string,Level> $map category-prefix => Level
     */
    public static function setLevels(array $map): void
    {
        LogRegistry::setLevels($map);
    }

    /**
     * Appends a sink to the process-global list of log destinations.
     *
     * Sinks accumulate rather than replace, and each applies its own minimum level
     * on top of the category threshold. With no sink registered, records are
     * discarded after level resolution.
     */
    public static function addSink(SinkInterface $sink): void
    {
        LogRegistry::addSink($sink);
    }

    /**
     * Restores the logging subsystem to its unconfigured state.
     *
     * Drops the registry's levels and sinks, clears any active {@see LogContext}
     * scopes, and empties this facade's logger cache so the next acquisition builds
     * a logger against the new configuration. For test isolation and
     * reconfiguration; not used on the request path.
     */
    public static function reset(): void
    {
        LogRegistry::reset();
        LogContext::clear();
        self::$cache = [];
    }

    // --- acquisition -------------------------------------------------------

    /**
     * The logger for an explicit dotted category, e.g. `Log::create(self::class)`
     * from a static method.
     *
     * Loggers are cached per category and shared by all call sites using it, so
     * calling this repeatedly is cheap and does not allocate. Unlike {@see for()},
     * the category is taken verbatim — pass an already-dotted name.
     */
    public static function create(string $category): CategoryLogger
    {
        return self::$cache[$category] ??= new CategoryLogger($category);
    }

    /**
     * Category logger for a class or object; the category is the FQCN with
     * namespace separators normalized to dots (so config prefixes like
     * "App.Orders" match "App\Orders\OrderService").
     */
    public static function for(object|string $classOrObject): CategoryLogger
    {
        $fqcn = is_object($classOrObject) ? $classOrObject::class : $classOrObject;
        $category = self::normalizeCategory($fqcn);
        return self::$cache[$category] ??= new CategoryLogger($category);
    }

    /**
     * Normalize a class name to a dotted category (leading separators stripped,
     * "\" -> ".").
     */
    public static function normalizeCategory(string $fqcn): string
    {
        return str_replace('\\', '.', ltrim($fqcn, '\\'));
    }
}
