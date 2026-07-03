<?php

namespace Quiote\Database;

use Quiote\Exception\DatabaseException;

/**
 * Shared base for ORM adapters whose {@see getConnection()} returns an ORM
 * manager (Eloquent Capsule, Doctrine EntityManager, Cycle ORM) rather than a
 * raw PDO handle. It pulls up the two things every such adapter needs:
 *
 *  1. Underlying-connection resolution in two modes:
 *     - *layer mode*  — the `connection` parameter is the name (string) of
 *       another configured {@see Database}; the ORM reuses that connection
 *       (credentials live in one place, PDO-level ping/reconnect is reused).
 *     - *standalone mode* — the ORM builds its own connection from the
 *       `connection` array or from flat dsn/username/password parameters.
 *  2. A worker-safe lifecycle skeleton (`shutdown()` nulls the handle; concrete
 *     adapters override `ping()`/`reset()` to clear per-request ORM state).
 *
 * Concrete adapters remain thin: they only translate resolved connection info
 * into their ORM's bootstrap and expose typed accessors.
 *
 * @see docs/DATABASE_ADAPTERS_PLAN.md §4
 */
abstract class AbstractOrmDatabase extends Database
{
    /**
     * Resolve the `connection` parameter to an underlying object:
     *  - string → the name of another configured Database; returns its
     *    getConnection() (typically a PDO or a DBAL Connection).
     *  - array  → returned as-is (inline connection details for the ORM).
     *  - null   → null (adapter should build its own from flat params).
     */
    protected function resolveUnderlyingConnection(): mixed
    {
        $connection = $this->getParameter('connection');

        if ($connection === null) {
            return null;
        }

        if (is_array($connection)) {
            return $connection;
        }

        if (is_string($connection)) {
            try {
                $referenced = $this->getDatabaseManager()->getDatabase($connection);
            } catch (DatabaseException $e) {
                throw new DatabaseException(sprintf(
                    '%s "%s" references database connection "%s", which is not configured.',
                    static::class,
                    $this->getName(),
                    $connection
                ), 0, $e);
            }

            if ($referenced === $this) {
                throw new DatabaseException(sprintf(
                    '%s "%s" references itself as its underlying connection.',
                    static::class,
                    $this->getName()
                ));
            }

            return $referenced->getConnection();
        }

        throw new DatabaseException(sprintf(
            '%s "%s": the "connection" parameter must be a string (name of another '
            . 'database), an array (inline connection details), or null.',
            static::class,
            $this->getName()
        ));
    }

    /**
     * Like {@see resolveUnderlyingConnection()} but requires the referenced
     * connection to be a PDO instance (for ORMs that layer on a raw PDO).
     */
    protected function resolveUnderlyingPdo(): \PDO
    {
        $conn = $this->resolveUnderlyingConnection();

        if ($conn instanceof \PDO) {
            return $conn;
        }

        throw new DatabaseException(sprintf(
            '%s "%s" requires its referenced "connection" to resolve to a PDO '
            . 'instance, but got %s.',
            static::class,
            $this->getName(),
            get_debug_type($conn)
        ));
    }

    /**
     * Assert that an ORM library is installed, with an actionable error message
     * naming the composer package to install.
     */
    protected function requireLibrary(string $probeClass, string $composerPackage): void
    {
        if (!class_exists($probeClass) && !interface_exists($probeClass)) {
            throw new DatabaseException(sprintf(
                '%s "%s" requires the "%s" package, but "%s" was not found. '
                . 'Install it with: composer require %s',
                static::class,
                $this->getName(),
                $composerPackage,
                $probeClass,
                $composerPackage
            ));
        }
    }

    /**
     * Default ORM shutdown: drop the manager and any cached resource. Concrete
     * adapters override to roll back dangling transactions / close underlying
     * connections first.
     */
    #[\Override]
    public function shutdown()
    {
        $this->connection = $this->resource = null;
    }
}
