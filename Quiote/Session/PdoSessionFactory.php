<?php

declare(strict_types=1);

namespace Quiote\Session;

use PDO;
use Quiote\Context;
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

        $connection = $context->getDatabaseConnection($name);
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
}
