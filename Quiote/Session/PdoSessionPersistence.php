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
    public function __construct(PDO $pdo, array $parameters = [])
    {
        $this->pdo = $pdo;
        $table = $parameters['table'] ?? null;
        // Interpolated straight into SQL below, so anything that isn't a plain
        // string is rejected rather than coerced.
        $this->table = is_string($table) && $table !== '' ? $table : 'session';
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

    /**
     * Narrow a decoded blob to the string-keyed shape load() promises. A JSON
     * array (`[1,2]`) or an igbinary payload holding a list decodes to integer
     * keys, which is not session data -- treat it as unreadable rather than
     * handing back something the caller's key lookups will silently miss.
     *
     * @return array<string, mixed>|null
     */
    private function asSessionData(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $result[$key] = $value;
        }

        return $result;
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
            // JSON payloads always start with '{' or '[', which igbinary's
            // binary format never does -- check the (cheap) shape first so a
            // JSON blob skips the igbinary_unserialize() attempt entirely,
            // instead of paying for a doomed decode attempt on every load().
            $looksLikeJson = str_starts_with($blob, '{') || str_starts_with($blob, '[');
            if (!$looksLikeJson && function_exists('igbinary_unserialize')) {
                try {
                    $decoded = $this->asSessionData(@igbinary_unserialize($blob));
                    if ($decoded !== null) {
                        return $decoded;
                    }
                } catch (Throwable) {
                }
            }
            if ($looksLikeJson) {
                $decoded = $this->asSessionData(json_decode($blob, true, 512, JSON_THROW_ON_ERROR));
                if ($decoded !== null) {
                    return $decoded;
                }
            }
            return null;
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
            $payload = null;
            if (function_exists('igbinary_serialize')) {
                try {
                    $payload = igbinary_serialize($data);
                } catch (Throwable) {
                    $payload = null;
                }
            }
            if ($payload === null) {
                $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
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
        } catch (PDOException) {
            // best-effort
        }
    }
}
