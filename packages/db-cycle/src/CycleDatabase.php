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
            return $config;
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
        return $this->getConnection();
    }

    public function getCycleDatabaseManager(): DatabaseProviderInterface
    {
        $this->getConnection(); // ensure connected → $this->resource populated
        return $this->resource;
    }

    /**
     * @param class-string|string $role
     */
    public function getRepository(string $role): \Cycle\ORM\RepositoryInterface
    {
        return $this->getOrm()->getRepository($role);
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
            } catch (\Throwable) {
                // best-effort
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
            } catch (\Throwable) {
                // best-effort
            }
        }
        $this->connection = $this->resource = null;
    }
}
