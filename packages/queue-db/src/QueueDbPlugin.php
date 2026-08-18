<?php

namespace Quiote\Queue\Db;

use PDO;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Database\PdoDatabase;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Queue\QueueDriverRegistry;
use RuntimeException;
use Quiote\DI\Container;

/**
 * Registers the `db` queue driver alias and publishes `queue.db.*` config
 * defaults. `DbQueueDriver`/`DbFailedJobStore` are registered as explicit
 * container services (not left to raw constructor autowiring) because they
 * need the app's *real*, already-`initialize()`d `DatabaseManager` — that
 * only exists on the current {@see Context}, not as a container-autowired
 * fresh instance (which would have no configured connections). See
 * {@see resolvePdo()}.
 *
 * `DbFailedJobStore` is registered as `DbFailedJobStore::class` only, not
 * bound as the default `FailedJobStoreInterface` — an app opts into
 * persistent dead-letter storage explicitly (`$registrar->service(FailedJobStoreInterface::class, ...)`
 * in its own plugin/bootstrap), rather than this package silently
 * overriding {@see \Quiote\Queue\QueuePlugin}'s default depending on plugin
 * declaration order.
 */
#[PluginAttribute(name: 'quiote/queue-db')]
final class QueueDbPlugin implements PluginInterface
{
    /**
     * Publishes the `queue.db.*` defaults and registers the `db` driver.
     *
     * Adds the `db` alias to {@see QueueDriverRegistry} and binds
     * {@see DbQueueDriver} and {@see DbFailedJobStore} as singleton services
     * whose factories pull the configured connection's PDO handle off the
     * current {@see Context}. Neither factory runs here — the connection is
     * only touched when something actually resolves one of those services.
     */
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('queue.db.connection', 'main');
        $registrar->configDefault('queue.db.table', 'quiote_queue_jobs');
        $registrar->configDefault('queue.db.failed_table', 'quiote_queue_failed_jobs');

        QueueDriverRegistry::register('db', DbQueueDriver::class);
        $registrar->stateReset('queue-driver-registry', static fn() => QueueDriverRegistry::reset());

        $registrar->service(
            DbQueueDriver::class,
            static fn() => new DbQueueDriver(
                self::resolvePdo(),
                Config::getString('queue.db.table', 'quiote_queue_jobs'),
                self::resolveClock(),
                self::resolveRandomness(),
            ),
            Container::SCOPE_SINGLETON,
        );

        $registrar->service(
            DbFailedJobStore::class,
            static fn() => new DbFailedJobStore(
                self::resolvePdo(),
                Config::getString('queue.db.failed_table', 'quiote_queue_failed_jobs'),
                self::resolveClock(),
                self::resolveRandomness(),
            ),
            Container::SCOPE_SINGLETON,
        );
    }

    private static function resolveClock(): \Quiote\Support\Clock\ClockInterface
    {
        return Context::getInstance(Config::getString('core.default_context', 'web'))
            ->getContainer()
            ->get(\Quiote\Support\Clock\ClockInterface::class);
    }

    private static function resolveRandomness(): \Quiote\Support\Random\RandomnessInterface
    {
        return Context::getInstance(Config::getString('core.default_context', 'web'))
            ->getContainer()
            ->get(\Quiote\Support\Random\RandomnessInterface::class);
    }

    private static function resolvePdo(): PDO
    {
        $connection = Config::getString('queue.db.connection', 'main');
        $context = Context::getInstance(Config::getString('core.default_context', 'web'));
        $databaseManager = $context->getContainer()->tryGet(\Quiote\Database\DatabaseManager::class);
        if ($databaseManager === null) {
            throw new RuntimeException('quioteframework/queue-db requires a DatabaseManager on the current Context; is databases.xml configured?');
        }
        $database = $databaseManager->getDatabase($connection);

        if (!$database instanceof PdoDatabase) {
            throw new RuntimeException(sprintf(
                'quioteframework/queue-db requires a PDO-backed database connection "%s" ("queue.db.connection"), got %s.',
                $connection,
                get_debug_type($database),
            ));
        }

        return $database->getPdo();
    }
}
