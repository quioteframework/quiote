<?php

declare(strict_types=1);

namespace Quiote\Session\Pdo;

use PDO;
use Quiote\Context;
use Quiote\Database\DatabaseManager;
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
    /**
     * Builds a {@see PdoSessionPersistence} over a connection from the
     * application's database manager.
     *
     * The `database` parameter names the connection; omitting it takes the
     * default one. `table` defaults to `session`.
     *
     * @throws StorageException if no database manager is bound (`core.use_database`
     *         off) or the named connection is not a PDO handle.
     */
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

        $table = $parameters['table'] ?? null;

        return new PdoSessionPersistence($connection, is_string($table) && $table !== '' ? $table : 'session');
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
