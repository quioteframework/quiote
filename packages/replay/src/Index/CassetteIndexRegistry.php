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

    /**
     * Builds every registered index, and turns one that cannot be constructed into an index that
     * declines with its reason rather than letting it take the others down.
     *
     * The eager `array_map` this replaces meant a single misconfigured factory aborted the whole
     * chain before any index existed -- and the shipped Azure configuration hit exactly that: the
     * Log Analytics index borrows `replay.store.azure.auth`, whose default `shared_key` cannot
     * authenticate an AAD-only API, so `AzureTokenProviderFactory` correctly threw and an
     * `--key` or `--date` that would have resolved fine never got the chance. That also defeated
     * {@see CassetteIndexChain}, which is deliberately built to record a broken index's failure and
     * fall through to the next one.
     *
     * @return list<CassetteIndexInterface>
     */
    public static function build(Container $container): array
    {
        $indexes = [];
        foreach (self::$factories as $factory) {
            try {
                $indexes[] = $factory($container);
            } catch (\Throwable $e) {
                $indexes[] = new UnavailableIndex($e);
            }
        }

        return $indexes;
    }

    public static function reset(): void
    {
        self::$factories = [];
    }
}
