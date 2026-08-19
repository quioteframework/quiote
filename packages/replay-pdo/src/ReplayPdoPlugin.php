<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Pdo;

use PDO;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Database\DatabaseManager;
use Quiote\DI\Container;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\CassetteStoreRegistry;
use RuntimeException;

/**
 * Registers the `pdo` cassette store alias and its `CassetteStoreInterface`
 * binding, through the same plugin mechanism every other Quiote package
 * uses.
 *
 * Load order does not matter, and installing this package does not commit an application to a
 * database-backed store. It contributes an alias, a factory and a config family; `ReplayPlugin`'s
 * single `CassetteStoreInterface` binding then builds whichever store `replay.store` actually
 * names. Previously this plugin claimed that binding itself with a set-if-absent `service()` call,
 * which only worked when it loaded first -- and, having loaded first, then won regardless of
 * `replay.store`.
 */
#[PluginAttribute(name: 'quioteframework/replay-pdo')]
final class ReplayPdoPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('replay.store.pdo.connection', 'main');
        $registrar->configDefault('replay.store.pdo.table', 'quiote_cassettes');

        // The factory travels with the alias, so `ReplayPlugin`'s single binding can build this
        // store when -- and only when -- `replay.store` says `pdo`.
        CassetteStoreRegistry::register(
            'pdo',
            PdoCassetteStore::class,
            static fn(Container $container): PdoCassetteStore => new PdoCassetteStore(
                self::resolvePdo(),
                Config::getString('replay.store.pdo.table', 'quiote_cassettes'),
            ),
        );
        $registrar->stateReset('quioteframework/replay-pdo', static fn() => CassetteStoreRegistry::reset());

    }

    private static function resolvePdo(): PDO
    {
        $connection = Config::getString('replay.store.pdo.connection', 'main');
        $context = Context::getInstance(Config::getString('core.default_context', 'web'));
        $databaseManager = $context->getContainer()->tryGet(DatabaseManager::class);
        if ($databaseManager === null) {
            throw new RuntimeException(
                'quioteframework/replay-pdo requires a DatabaseManager on the current Context; is databases.xml configured?',
            );
        }

        return $databaseManager->getDatabase($connection)->getPdo();
    }
}
