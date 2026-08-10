<?php

namespace Quiote\Database\Adapter\Cycle;

use Quiote\Database\AbstractOrmDatabase;
use Quiote\Exception\DatabaseException;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Factory;
use Cycle\ORM\Schema;
use Cycle\Database\DatabaseManager as CycleDatabaseManager;
use Cycle\Database\DatabaseProviderInterface;
use Cycle\Database\Config\DatabaseConfig;

/**
 * First-class adapter for Cycle ORM v2 — the data-mapper built for long-running
 * (RoadRunner/FrankenPHP) processes, a natural fit for Quiote's worker mode.
 * {@see getConnection()} returns the {@see ORMInterface}.
 *
 * Configuration parameters (in `databases.xml`):
 *  - `cycle`           : a native Cycle DatabaseConfig array (`default`,
 *                        `databases`, `connections`). Required — Cycle owns its
 *                        own connection/driver configuration.
 *  - `schema`          : a precompiled Cycle schema array, OR
 *  - `schema_provider` : a callable(self): (Schema|array) that returns the schema.
 *
 * Schema *compilation* from annotated entities (cycle/annotated +
 * cycle/schema-builder) is an app/console concern, not something this adapter
 * does on every boot — supply a compiled/cached schema here.
 */
class CycleDatabase extends AbstractOrmDatabase
{
    protected function connect()
    {
        $this->requireLibrary(ORM::class, 'cycle/orm');
        $this->requireLibrary(CycleDatabaseManager::class, 'cycle/database');

        $dbal = new CycleDatabaseManager(new DatabaseConfig($this->buildDatabaseConfig()));

        $this->connection = new ORM(new Factory($dbal), $this->buildSchema());
        $this->resource = $dbal;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDatabaseConfig(): array
    {
        $config = $this->getParameter('cycle');
        if (is_array($config)) {
            $result = [];
            foreach ($config as $key => $value) {
                if (!is_string($key)) {
                    throw new DatabaseException(sprintf(
                        'CycleDatabase "%s": "cycle" parameter array must have string keys '
                        . '(default, databases, connections), got %s.',
                        $this->getName(),
                        get_debug_type($key)
                    ));
                }
                $result[$key] = $value;
            }
            return $result;
        }

        throw new DatabaseException(sprintf(
            'CycleDatabase "%s" requires a "cycle" parameter containing a native '
            . 'Cycle DatabaseConfig array (default, databases, connections).',
            $this->getName()
        ));
    }

    protected function buildSchema(): Schema
    {
        $provider = $this->getParameter('schema_provider');
        if (is_callable($provider)) {
            $schema = $provider($this);
            if ($schema instanceof Schema) {
                return $schema;
            }
            if (is_array($schema)) {
                return new Schema($schema);
            }
            throw new DatabaseException(sprintf(
                'CycleDatabase "%s": "schema_provider" must return a Cycle\ORM\Schema '
                . 'or a schema array, got %s.',
                $this->getName(),
                get_debug_type($schema)
            ));
        }

        $schema = $this->getParameter('schema');
        if (is_array($schema)) {
            return new Schema($schema);
        }
        if ($schema instanceof Schema) {
            return $schema;
        }

        throw new DatabaseException(sprintf(
            'CycleDatabase "%s" requires a compiled schema: provide a "schema" array '
            . '(or Schema), or a "schema_provider" callable. Schema compilation from '
            . 'annotated entities is an app/console concern.',
            $this->getName()
        ));
    }

    // --- typed accessors ----------------------------------------------------

    /**
     * Returns the Cycle ORM instance, connecting on first use.
     *
     * @throws DatabaseException If the connection could not be built, or what
     *                           was built is not an ORMInterface.
     */
    public function getOrm(): ORMInterface
    {
        $connection = $this->getConnection();
        if ($connection instanceof ORMInterface) {
            return $connection;
        }

        throw new DatabaseException(sprintf(
            'CycleDatabase "%s" connection is not an ORMInterface (got %s).',
            $this->getName(),
            get_debug_type($connection)
        ));
    }

    /**
     * Returns Cycle's own database manager, the DBAL layer beneath the ORM.
     *
     * Triggers a connect first so the resource is populated. Use this to reach
     * query builders and raw `query()`/`execute()` calls, which is how custom
     * SQL is written for this adapter.
     *
     * @throws DatabaseException If the connection could not be built, or the
     *                           resource is not a DatabaseProviderInterface.
     */
    public function getCycleDatabaseManager(): DatabaseProviderInterface
    {
        $this->getConnection(); // ensure connected → $this->resource populated
        if ($this->resource instanceof DatabaseProviderInterface) {
            return $this->resource;
        }

        throw new DatabaseException(sprintf(
            'CycleDatabase "%s" resource is not a DatabaseProviderInterface (got %s).',
            $this->getName(),
            get_debug_type($this->resource)
        ));
    }

    /**
     * @param class-string|non-empty-string $role
     * @return \Cycle\ORM\RepositoryInterface<object>
     */
    public function getRepository(string $role): \Cycle\ORM\RepositoryInterface
    {
        return $this->getOrm()->getRepository($role);
    }

    /**
     * Cycle's driver never exposes its underlying PDO/PDOInterface publicly
     * (`Driver::getPDO()` is protected, and its return type isn't even
     * guaranteed to be `\PDO`). Write custom SQL via the Cycle database's own
     * `query()`/`execute()` methods, or `Cycle\Database\Injection\Fragment`
     * inside a query builder, instead of dropping to raw PDO.
     */
    #[\Override]
    public function getPdo(): \PDO
    {
        throw new DatabaseException(sprintf(
            'CycleDatabase "%s" does not expose a raw PDO connection. Use '
            . 'getCycleDatabaseManager()->database()->query()/execute() for custom '
            . 'SQL, or Cycle\Database\Injection\Fragment for raw expressions inside '
            . 'a query builder.',
            $this->getName()
        ));
    }

    // --- worker lifecycle ---------------------------------------------------

    /**
     * Probes the connection with `SELECT 1` through Cycle's database manager.
     *
     * Returns true when nothing has been connected yet, since lazy connect
     * handles it on first use. If the probe throws, the ORM and the DBAL
     * resource are both cleared so the next getConnection() rebuilds them, and
     * false is returned.
     */
    #[\Override]
    public function ping(): bool
    {
        if ($this->connection === null) {
            return true;
        }
        try {
            if ($this->resource instanceof DatabaseProviderInterface) {
                $this->resource->database()->query('SELECT 1');
            }
            return true;
        } catch (\Throwable) {
            $this->connection = $this->resource = null;
            return false;
        }
    }

    /**
     * Returns this database to its pre-initialize() state, cleaning the ORM
     * heap first.
     *
     * The heap (identity map) is cleaned up front so hydrated entities are
     * detached even for a caller still holding the ORM; the base teardown
     * then shuts the connection down and clears the parameters, the manager
     * reference and the name -- including the compiled schema, which is
     * rebuilt from the parameters on the next initialize(). Re-initialize()
     * this instance before using it again.
     *
     * To recycle a worker's connection between requests without discarding
     * the configuration, use {@see ping()} -- which is what
     * {@see \Quiote\Database\DatabaseManager::recycleConnections()} calls at
     * the request boundary.
     *
     * @throws DatabaseException If shutting the connection down fails.
     */
    #[\Override]
    public function reset(): void
    {
        if ($this->connection instanceof ORMInterface) {
            try {
                $this->connection->getHeap()->clean();
            } catch (\Throwable $e) {
                // The teardown below drops this database's own reference either way, but a caller
                // still holding the ORM keeps a live heap of stale hydrated entities.
                \Quiote\Logging\Log::for($this)->warning(
                    'Could not clean the ORM heap while resetting; entities '
                    . 'hydrated through it stay attached: ' . $e->getMessage()
                );
            }
        }
        parent::reset();
    }

    /**
     * Cleans the ORM heap and drops the ORM and DBAL resource.
     *
     * A heap that refuses to clean is logged at debug and does not stop the
     * shutdown, since the heap goes away with the connection anyway.
     */
    #[\Override]
    public function shutdown()
    {
        if ($this->connection instanceof ORMInterface) {
            try {
                $this->connection->getHeap()->clean();
            } catch (\Throwable $e) {
                // Shutdown continues either way; the heap is dropped with the connection below.
                \Quiote\Logging\Log::for($this)->debug(
                    'Could not clean the ORM heap on shutdown: ' . $e->getMessage()
                );
            }
        }
        $this->connection = $this->resource = null;
    }
}
