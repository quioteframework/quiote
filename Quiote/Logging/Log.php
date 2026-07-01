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

    // --- configuration -----------------------------------------------------

    public static function setDefaultLevel(Level $level): void
    {
        LogRegistry::setDefaultLevel($level);
    }

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

    public static function addSink(SinkInterface $sink): void
    {
        LogRegistry::addSink($sink);
    }

    public static function reset(): void
    {
        LogRegistry::reset();
        LogContext::clear();
    }

    // --- acquisition -------------------------------------------------------

    public static function create(string $category): CategoryLogger
    {
        return new CategoryLogger($category);
    }

    /**
     * Category logger for a class or object; the category is the FQCN with
     * namespace separators normalized to dots (so config prefixes like
     * "App.Orders" match "App\Orders\OrderService").
     */
    public static function for(object|string $classOrObject): CategoryLogger
    {
        $fqcn = is_object($classOrObject) ? $classOrObject::class : $classOrObject;
        return new CategoryLogger(self::normalizeCategory($fqcn));
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
