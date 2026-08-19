<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\ORM\Configuration as OrmConfiguration;
use Quiote\Database\Adapter\Doctrine\DoctrineDatabase;

/**
 * {@see DoctrineDatabase}, with {@see DoctrineRecordingMiddleware} installed
 * on every DBAL connection it builds. Registered under the `doctrine` driver
 * alias by {@see ReplayDoctrinePlugin} in place of the plain
 * {@see DoctrineDatabase} `quioteframework/db-doctrine`'s own `DoctrinePlugin`
 * registers.
 *
 * `buildOrmConfiguration()` is the seam {@see DoctrineDatabase} already
 * exposes for exactly this: DBAL only accepts a `Configuration`'s middlewares
 * at `DriverManager::getConnection($params, $config)` time (inside the
 * inherited `connect()`), so they cannot be added after the fact once a
 * `Doctrine\ORM\EntityManager`/`Doctrine\DBAL\Connection` already exists.
 */
final class ReplayDoctrineDatabase extends DoctrineDatabase
{
    #[\Override]
    protected function buildOrmConfiguration(): OrmConfiguration
    {
        $config = parent::buildOrmConfiguration();
        $config->setMiddlewares([...$config->getMiddlewares(), new DoctrineRecordingMiddleware()]);

        return $config;
    }
}
