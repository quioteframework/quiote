<?php

namespace Quiote\Database\Adapter\Eloquent;

use Quiote\Database\AbstractOrmDatabase;
use Quiote\Exception\DatabaseException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection as IlluminateConnection;

/**
 * First-class adapter for Eloquent (illuminate/database) used standalone via the
 * Capsule Manager. {@see getConnection()} returns the {@see Capsule}; models
 * (`extends Illuminate\Database\Eloquent\Model`) work once `global`/`boot_eloquent`
 * is enabled.
 *
 * Configuration parameters (in `databases.xml`):
 *  - `connection`      : inline Eloquent config array, OR the name of another
 *                        configured database to borrow a live PDO from (layer
 *                        mode — still requires a `driver` so Eloquent knows the
 *                        SQL grammar). Omit for standalone mode using flat params.
 *  - `driver`          : mysql | pgsql | sqlite | sqlsrv (required unless an
 *                        inline `connection` array supplies it)
 *  - `host`,`port`,`database`,`username`,`password`,`charset`,`collation`,`prefix`
 *  - `connection_name` : Capsule connection name (default "default")
 *  - `global`          : call setAsGlobal() (default false)
 *  - `boot_eloquent`   : call bootEloquent() (default = value of `global`)
 */
class EloquentDatabase extends AbstractOrmDatabase
{
    protected function connect()
    {
        $this->requireLibrary(Capsule::class, 'illuminate/database');

        $name = $this->connectionName();

        $capsule = new Capsule();
        $capsule->addConnection($this->buildConnectionConfig(), $name);

        // Layer mode: borrow an already-open PDO from another configured database.
        if (is_string($this->getParameter('connection'))) {
            $capsule->getConnection($name)->setPdo($this->resolveUnderlyingPdo());
        }

        if ($this->getParameter('global', false)) {
            $capsule->setAsGlobal();
        }
        if ($this->getParameter('boot_eloquent', $this->getParameter('global', false))) {
            $capsule->bootEloquent();
        }

        $this->connection = $capsule;
        $this->resource = $capsule->getConnection($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConnectionConfig(): array
    {
        $connection = $this->getParameter('connection');
        if (is_array($connection)) {
            return $connection;
        }

        $config = array_filter([
            'driver'    => $this->getParameter('driver'),
            'host'      => $this->getParameter('host'),
            'port'      => $this->getParameter('port'),
            'database'  => $this->getParameter('database'),
            'username'  => $this->getParameter('username'),
            'password'  => $this->getParameter('password'),
            'charset'   => $this->getParameter('charset'),
            'collation' => $this->getParameter('collation'),
            'prefix'    => $this->getParameter('prefix'),
        ], static fn($v) => $v !== null);

        if (!isset($config['driver'])) {
            throw new DatabaseException(sprintf(
                'EloquentDatabase "%s" requires a "driver" parameter (mysql, pgsql, '
                . 'sqlite, sqlsrv) or an inline "connection" array.',
                $this->getName()
            ));
        }

        // Eloquent's ConnectionFactory always reads config['database'] (warns if
        // absent). In layer mode the borrowed PDO makes it irrelevant, and for a
        // genuinely missing database Eloquent will raise its own clearer error at
        // connect time — so guarantee the key exists to keep the adapter clean.
        $config['database'] ??= '';

        return $config;
    }

    private function connectionName(): string
    {
        return (string) $this->getParameter('connection_name', 'default');
    }

    // --- typed accessors ----------------------------------------------------

    public function getCapsule(): Capsule
    {
        return $this->getConnection();
    }

    /** The underlying Illuminate connection (query builder, PDO, transactions). */
    public function getEloquentConnection(): IlluminateConnection
    {
        return $this->getCapsule()->getConnection($this->connectionName());
    }

    #[\Override]
    public function getPdo(): \PDO
    {
        return $this->getEloquentConnection()->getPdo();
    }

    // --- worker lifecycle ---------------------------------------------------

    #[\Override]
    public function ping(): bool
    {
        if ($this->connection === null) {
            return true;
        }
        try {
            $this->getEloquentConnection()->getPdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            $this->connection = $this->resource = null;
            return false;
        }
    }

    #[\Override]
    public function shutdown()
    {
        if ($this->connection instanceof Capsule) {
            try {
                $conn = $this->connection->getConnection($this->connectionName());
                if ($conn->transactionLevel() > 0) {
                    $conn->rollBack(0);
                }
            } catch (\Throwable) {
                // best-effort cleanup
            }
            try {
                $this->connection->getDatabaseManager()->purge($this->connectionName());
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        $this->connection = $this->resource = null;
    }
}
