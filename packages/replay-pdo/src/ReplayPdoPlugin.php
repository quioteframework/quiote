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
 * **Must be loaded before `Quiote\Replay\ReplayPlugin`** for `replay.store =
 * pdo` to actually take effect: `PluginRegistrar::service()` is set-if-absent
 * (first registration wins), and `ReplayPlugin`'s own `CassetteStoreInterface`
 * factory only knows how to build the built-in file store -- its docblock
 * states plainly that a non-file store's own plugin is responsible for
 * registering the service ahead of it. This mirrors
 * `quioteframework/queue-db`'s `QueueDbPlugin` exactly.
 */
#[PluginAttribute(name: 'quioteframework/replay-pdo')]
final class ReplayPdoPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('replay.store.pdo.connection', 'main');
        $registrar->configDefault('replay.store.pdo.table', 'quiote_cassettes');

        CassetteStoreRegistry::register('pdo', PdoCassetteStore::class);
        $registrar->stateReset('quioteframework/replay-pdo', static fn() => CassetteStoreRegistry::reset());

        $registrar->service(
            CassetteStoreInterface::class,
            static fn(): CassetteStoreInterface => new PdoCassetteStore(
                self::resolvePdo(),
                Config::getString('replay.store.pdo.table', 'quiote_cassettes'),
            ),
            Container::SCOPE_SINGLETON,
        );
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
