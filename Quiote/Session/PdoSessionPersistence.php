<?php

declare(strict_types=1);

namespace Quiote\Session;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use Quiote\Exception\StorageException;

/**
 * Default PDO-backed SessionPersistenceInterface implementation. Works on
 * Postgres, MySQL and SQLite -- the upsert is chosen per driver, since no
 * single statement is portable across all three (see buildSaveSql()).
 *
 * Expects a table with (at least) sess_id/sess_data/sess_time columns, matching
 * the schema most PHP session table conventions already use:
 *
 *   CREATE TABLE session (
 *       sess_id   VARCHAR(64) PRIMARY KEY,
 *       sess_data BYTEA/BLOB/TEXT NOT NULL,
 *       sess_time TIMESTAMP NOT NULL
 *   );
 */
class PdoSessionPersistence implements SessionPersistenceInterface
{
    private PDO $pdo;
    private string $table;

    /**
     * Prepared statements cached per instance: $this->table is fixed at
     * construction and the PDO connection lives for the worker's lifetime,
     * so re-preparing the same SQL on every load()/save()/delete() call
     * was pure waste.
     */
    private ?PDOStatement $loadStmt = null;
    private ?PDOStatement $saveStmt = null;
    private ?PDOStatement $deleteStmt = null;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        PDO $pdo,
        array $parameters = [],
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: true),
    ) {
        $this->pdo = $pdo;
        $table = $parameters['table'] ?? null;
        // Interpolated straight into SQL below, so anything that isn't a plain
        // string is rejected rather than coerced.
        $this->table = self::assertValidTableName(is_string($table) && $table !== '' ? $table : 'session');
    }

    /**
     * Table names are interpolated into SQL (an identifier cannot be bound as a
     * parameter), so the value is restricted to a plain SQL identifier. It comes
     * from operator config rather than from a request, so this guards a
     * configuration mistake rather than an attacker -- the same allow-list
     * {@see \Quiote\Security\Auth\Provider\PdoUserProvider} and the queue /
     * rate-limit storages already apply to theirs.
     *
     * @throws     \InvalidArgumentException If $table is not a valid SQL identifier.
     */
    private static function assertValidTableName(string $table): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid session table name "%s".', $table));
        }

        return $table;
    }

    private function loadStatement(): PDOStatement
    {
        return $this->loadStmt ??= $this->pdo->prepare("SELECT sess_data FROM {$this->table} WHERE sess_id = ?");
    }

    private function saveStatement(): PDOStatement
    {
        return $this->saveStmt ??= $this->pdo->prepare($this->buildSaveSql());
    }

    /**
     * The upsert, per driver.
     *
     * Neither half of this is portable on its own: MySQL has NOW() but not
     * ON CONFLICT, SQLite has ON CONFLICT but no NOW(), and SQL Server has
     * neither. Dispatching mirrors what the legacy
     * {@see \Quiote\Storage\PdoSessionStorage} already does, so an application
     * is not silently limited to Postgres.
     */
    private function buildSaveSql(): string
    {
        return match ($this->driverName()) {
            'mysql' => "INSERT INTO {$this->table} (sess_id, sess_data, sess_time) VALUES (?, ?, NOW()) "
                . 'ON DUPLICATE KEY UPDATE sess_data = VALUES(sess_data), sess_time = VALUES(sess_time)',
            'sqlite' => "INSERT INTO {$this->table} (sess_id, sess_data, sess_time) VALUES (?, ?, CURRENT_TIMESTAMP) "
                . 'ON CONFLICT (sess_id) DO UPDATE SET sess_data = excluded.sess_data, sess_time = excluded.sess_time',
            // Postgres, and the historical default.
            default => "INSERT INTO {$this->table} (sess_id, sess_data, sess_time) VALUES (?, ?, NOW()) "
                . 'ON CONFLICT (sess_id) DO UPDATE SET sess_data = EXCLUDED.sess_data, sess_time = EXCLUDED.sess_time',
        };
    }

    private function driverName(): string
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (PDOException) {
            return '';
        }

        return is_string($driver) ? $driver : '';
    }

    private function deleteStatement(): PDOStatement
    {
        return $this->deleteStmt ??= $this->pdo->prepare("DELETE FROM {$this->table} WHERE sess_id = ?");
    }

    public function load(string $sid): ?array
    {
        $stmt = null;

        try {
            $stmt = $this->loadStatement();
            $stmt->execute([$sid]);
            $blob = $stmt->fetchColumn();
            if (in_array($blob, [false, null, ''], true)) {
                return null;
            }
            if (!is_string($blob)) {
                return null;
            }

            return $this->codec->decode($blob);
        } catch (PDOException $e) {
            throw new StorageException('Failed loading session row: ' . $e->getMessage(), (int)$e->getCode(), $e);
        } finally {
            // Release the cursor. fetchColumn() does not exhaust the result set, so the
            // statement stays open and, on SQLite, keeps the connection inside an implicit
            // read transaction holding a shared lock. save()'s upsert then has to upgrade
            // shared -> exclusive, which SQLite refuses immediately with SQLITE_BUSY
            // (busy_timeout deliberately does not apply to upgrades). The statement is
            // cached in $loadStmt, so an open cursor also makes the next execute() fail
            // with "bad parameter or other API misuse".
            $stmt?->closeCursor();
        }
    }

    public function save(string $sid, array $data): void
    {
        try {
            $payload = $this->codec->encode($data);
            $stmt = $this->saveStatement();
            $stmt->bindParam(1, $sid, PDO::PARAM_STR);
            $stmt->bindParam(2, $payload, PDO::PARAM_LOB);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new StorageException('Failed writing session row: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    public function delete(string $sid): void
    {
        try {
            $this->deleteStatement()->execute([$sid]);
        } catch (PDOException $e) {
            // The row survives, so the session it holds can still be loaded until it expires --
            // which matters most when the delete is a logout.
            \Quiote\Logging\Log::for($this)->error(
                '[PdoSessionPersistence] could not delete session row "' . $sid
                . '"; the session data survives: ' . $e->getMessage()
            );
        }
    }
}
