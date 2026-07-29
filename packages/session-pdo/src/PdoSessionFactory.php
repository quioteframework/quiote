<?php

declare(strict_types=1);

namespace Quiote\Session\Pdo;

use PDO;
use Quiote\Context;
use Quiote\Exception\StorageException;
use Quiote\Session\SessionFactoryInterface;
use Quiote\Session\SessionPersistenceInterface;

/**
 * `session` slot factory for this package's {@see PdoSessionPersistence}.
 *
 * ```yaml
 * session:
 *   class: Quiote\Session\Pdo\PdoSessionFactory
 *   params:
 *     database: sessions
 *     table: session
 * ```
 *
 * Core ships an equivalent pair ({@see \Quiote\Session\PdoSessionFactory} over
 * {@see \Quiote\Session\PdoSessionPersistence}) with no extra dependency, and
 * that is the one to reach for in a new application. This exists so an
 * application already requiring this package keeps working.
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

        $table = $parameters['table'] ?? null;

        return new PdoSessionPersistence($connection, is_string($table) && $table !== '' ? $table : 'session');
    }
}
