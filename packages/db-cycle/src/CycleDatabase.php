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
     * Per-request boundary: clean the ORM heap (identity map) so hydrated entities
     * don't bleed into the next request; keep the compiled schema + connections.
     */
    #[\Override]
    public function reset(): void
    {
        if ($this->connection instanceof ORMInterface) {
            try {
                $this->connection->getHeap()->clean();
            } catch (\Throwable $e) {
                // The heap keeps this request's hydrated entities, so the next request served by
                // this worker can read stale ones -- which is the whole reason this runs.
                \Quiote\Logging\Log::for($this)->warning(
                    '[CycleDatabase] could not clean the ORM heap at the request boundary; entities '
                    . 'from this request may leak into the next: ' . $e->getMessage()
                );
            }
        }
        parent::reset();
    }

    #[\Override]
    public function shutdown()
    {
        if ($this->connection instanceof ORMInterface) {
            try {
                $this->connection->getHeap()->clean();
            } catch (\Throwable $e) {
                // Shutdown continues either way; the heap is dropped with the connection below.
                \Quiote\Logging\Log::for($this)->debug(
                    '[CycleDatabase] could not clean the ORM heap on shutdown: ' . $e->getMessage()
                );
            }
        }
        $this->connection = $this->resource = null;
    }
}
