<?php

declare(strict_types=1);

namespace Quiote\Replay\Index;

use Quiote\DI\Container;

/**
 * The ordered list of {@see CassetteIndexInterface} factories a driver package (today, only
 * `quioteframework/replay-azure`) contributes -- unlike {@see \Quiote\Replay\Store\CassetteStoreRegistry}'s
 * alias-to-class map, resolving a bare id is a *chain* to try in order, not a single named choice,
 * so this registry holds factories, appended in registration order, rather than named aliases.
 *
 * A factory is a closure so that building an index (a real HTTP client, real credentials) stays
 * lazy: registration happens during {@see \Quiote\Plugin\PluginManager::bootFromConfig()}, before
 * a request's container necessarily exists, and only `quiote cassette:fetch`/`quiote replay --save`
 * ever actually need one built.
 */
final class CassetteIndexRegistry
{
    /** @var list<\Closure(Container): CassetteIndexInterface> */
    private static array $factories = [];

    /** @param \Closure(Container): CassetteIndexInterface $factory */
    public static function register(\Closure $factory): void
    {
        self::$factories[] = $factory;
    }

    /** @return list<CassetteIndexInterface> */
    public static function build(Container $container): array
    {
        return array_map(static fn(\Closure $factory): CassetteIndexInterface => $factory($container), self::$factories);
    }

    public static function reset(): void
    {
        self::$factories = [];
    }
}
