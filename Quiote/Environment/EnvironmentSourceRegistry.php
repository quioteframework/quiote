<?php

declare(strict_types=1);

namespace Quiote\Environment;

use Quiote\DI\Container;

/**
 * Process-global registry of environment sources, in the same spirit as
 * {@see \Quiote\Database\DatabaseDriverRegistry}: plugins contribute a factory
 * during {@see \Quiote\Plugin\PluginManager::bootFromConfig()} (via
 * {@see \Quiote\Plugin\PluginRegistrar::environmentSource()}), applied
 * immediately like that registry's aliases; `env:list` resolves every
 * contributed factory against a built container once one exists.
 *
 * A factory returning null means "this source doesn't apply to the current
 * config" (e.g. `replay.store` isn't `azure-blob`), not an error -- the
 * source is simply omitted rather than the whole command failing.
 */
final class EnvironmentSourceRegistry
{
    /** @var array<string, \Closure(Container): ?EnvironmentSourceInterface> */
    private static array $factories = [];

    private function __construct()
    {
    }

    /** @param \Closure(Container): ?EnvironmentSourceInterface $factory */
    public static function register(string $id, \Closure $factory): void
    {
        self::$factories[$id] = $factory;
    }

    /** @return array<string, EnvironmentSourceInterface> */
    public static function resolve(Container $container): array
    {
        $sources = [];
        foreach (self::$factories as $id => $factory) {
            $source = $factory($container);
            if ($source !== null) {
                $sources[$id] = $source;
            }
        }

        return $sources;
    }

    /** Test isolation: clears every contributed factory. */
    public static function reset(): void
    {
        self::$factories = [];
    }
}
