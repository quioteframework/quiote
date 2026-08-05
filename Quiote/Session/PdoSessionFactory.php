<?php

declare(strict_types=1);

namespace Quiote\Session;

use PDO;
use Quiote\Context;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\StorageException;

/**
 * `session` slot factory for {@see PdoSessionPersistence}, taking its
 * connection from the application's own database manager so sessions live
 * alongside everything else rather than needing separate credentials.
 *
 * Parameters: `database` (the connection name from databases.xml; the default
 * connection when omitted) and `table` (defaults to `session`).
 *
 * A dedicated connection is worth considering under SQLite, where session
 * writes and application writes on one file contend for the same lock.
 *
 * @since      3.0.0
 */
final class PdoSessionFactory implements SessionFactoryInterface
{
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        $database = $parameters['database'] ?? null;
        $name = is_string($database) && $database !== '' ? $database : null;

        $connection = self::connectionFor($context, $name);
        if (!$connection instanceof PDO) {
            throw new StorageException(sprintf(
                'The session backend needs a PDO connection, but database "%s" resolved to %s. '
                . 'Check that core.use_database is on and the connection is declared in databases.xml.',
                $name ?? '(default)',
                get_debug_type($connection),
            ));
        }

        return new PdoSessionPersistence($connection, $parameters);
    }

    /**
     * The PDO connection for a declared database, or null when there is none to be had.
     *
     * Resolved through the container rather than through a Context accessor: the accessors are being
     * removed, and a session factory is exactly the kind of collaborator that should ask for what it
     * needs by name. `has()` first, because a context with `core.use_database` off never binds a
     * database manager at all -- and "no database configured" is a case this factory reports rather
     * than an error to propagate from the container.
     *
     * @since      4.0.0
     */
    private static function connectionFor(Context $context, ?string $name): mixed
    {
        $container = $context->getContainer();
        if (!$container->has(DatabaseManager::class)) {
            return null;
        }

        return $container->get(DatabaseManager::class)->getDatabase($name)->getConnection();
    }
}
