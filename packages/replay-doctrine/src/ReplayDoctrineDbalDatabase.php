<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Doctrine\DBAL\Configuration;
use Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase;

/**
 * {@see DoctrineDbalDatabase}, with {@see DoctrineRecordingMiddleware}
 * installed on the connection it builds. Registered under the
 * `doctrine_dbal` driver alias by {@see ReplayDoctrinePlugin} in place of the
 * plain {@see DoctrineDbalDatabase} `quioteframework/db-doctrine`'s own
 * `DoctrinePlugin` registers.
 */
final class ReplayDoctrineDbalDatabase extends DoctrineDbalDatabase
{
    #[\Override]
    protected function buildDbalConfiguration(): Configuration
    {
        $config = parent::buildDbalConfiguration() ?? new Configuration();
        $config->setMiddlewares([...$config->getMiddlewares(), new DoctrineRecordingMiddleware()]);

        return $config;
    }
}
