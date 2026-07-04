<?php

namespace Quiote\Database\Adapter\Doctrine;

use Quiote\Database\AbstractOrmDatabase;
use Quiote\Exception\DatabaseException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection as DbalConnection;

/**
 * Tier-2 adapter: a Doctrine DBAL connection (connection abstraction + query
 * builder) without the ORM/entity layer. {@see getConnection()} returns the
 * {@see DbalConnection}.
 *
 * Parameters: either an inline `connection` array, a DSN `url`, or flat
 * `driver`/`host`/`dbname`/`user`/`password` params (see {@see DoctrineDbalParams}).
 */
class DoctrineDbalDatabase extends AbstractOrmDatabase
{
    use DoctrineDbalParams;

    protected function connect()
    {
        $this->requireLibrary(DriverManager::class, 'doctrine/dbal');

        $connection = $this->getParameter('connection');
        $params = is_array($connection) ? $connection : $this->dbalParams();

        if (!$params) {
            throw new DatabaseException(sprintf(
                'DoctrineDbalDatabase "%s" needs connection details: an inline '
                . '"connection" array, a "url", or flat driver params.',
                $this->getName()
            ));
        }

        try {
            $this->connection = $this->resource = DriverManager::getConnection($params);
        } catch (\Throwable $e) {
            throw new DatabaseException(sprintf(
                'DoctrineDbalDatabase "%s" could not create a DBAL connection: %s',
                $this->getName(),
                $e->getMessage()
            ), 0, $e);
        }
    }

    public function getDbalConnection(): DbalConnection
    {
        return $this->getConnection();
    }

    public function getQueryBuilder(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->getDbalConnection()->createQueryBuilder();
    }

    #[\Override]
    public function ping(): bool
    {
        if ($this->connection === null) {
            return true;
        }
        try {
            $this->getDbalConnection()->executeQuery('SELECT 1');
            return true;
        } catch (\Throwable) {
            $this->connection = $this->resource = null;
            return false;
        }
    }

    #[\Override]
    public function shutdown()
    {
        if ($this->connection instanceof DbalConnection) {
            try {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                $this->connection->close();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        $this->connection = $this->resource = null;
    }
}
