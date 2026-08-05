<?php

declare(strict_types=1);

namespace Quiote\Config;

/**
 * Reads the value of a compiled configuration through whichever cache implementation is active.
 *
 * A caller wants the value, not the storage: whether the compiled configuration lives in a file on
 * disk or as a value in shared memory is the cache's business, and reading it should not require the
 * caller to know which.
 *
 * The choice of implementation lives here so the cache classes stay unaware of each other:
 * {@see ConfigCache} does not name its APCu subclass, and the subclass overrides one method rather
 * than reimplementing the selection.
 *
 * @since      4.0.0
 */
final class CompiledConfig
{
    /**
     * The value a compiled configuration returns.
     *
     * @param      string $config An absolute or relative filesystem path to a configuration file.
     * @param      string|null $context An optional context name.
     * @return     mixed The compiled configuration's return value.
     * @since      4.0.0
     */
    public static function value(string $config, ?string $context = null): mixed
    {
        $cache = self::cacheClass();

        return $cache::loadValue($config, $context);
    }

    /**
     * The active cache implementation.
     *
     * Reads the constant rather than probing APCu directly: the runtime decides once, at bootstrap,
     * whether the APCu store is in play, and a config read must not come to a different conclusion
     * mid-request.
     *
     * @return     class-string<ConfigCache>
     * @since      4.0.0
     */
    private static function cacheClass(): string
    {
        if (defined('QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE) {
            return APCuConfigCache::class;
        }

        return ConfigCache::class;
    }
}
