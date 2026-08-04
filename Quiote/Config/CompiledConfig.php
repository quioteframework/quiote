<?php

declare(strict_types=1);

namespace Quiote\Config;

/**
 * Reads the value of a compiled configuration through whichever cache implementation is active.
 *
 * Every caller that needed a compiled config's value used to carry the same four lines: check the
 * APCu constant, call one cache class or the other, test the result for an `APCU:` marker, and
 * eval() the source when it found one. That put the cache's storage format in the hands of its
 * consumers -- five copies to keep in step, and five places that had to compile PHP at runtime.
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
