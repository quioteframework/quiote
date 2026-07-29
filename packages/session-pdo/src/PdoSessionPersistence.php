<?php

declare(strict_types=1);

namespace Quiote\Session\Pdo;

use JsonException;
use PDO;
use PDOException;
use Quiote\Exception\StorageException;
use Quiote\Session\SessionPersistenceInterface;
use Throwable;

/**
 * PDO-backed {@see SessionPersistenceInterface} for {@see \Quiote\Session\SessionManager}.
 * One row per session id; the payload is JSON (igbinary, if the extension is
 * loaded, purely as a smaller-and-faster wire format — JSON is always the
 * fallback and the only format {@see load()} needs to recognize besides it).
 *
 * Expects a table shaped like:
 *
 *   CREATE TABLE session (
 *       sess_id   VARCHAR(64) PRIMARY KEY,
 *       sess_data BYTEA/BLOB/TEXT NOT NULL,
 *       sess_time TIMESTAMP NOT NULL
 *   );
 */
final class PdoSessionPersistence implements SessionPersistenceInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'session',
    ) {
    }

    #[\Override]
    public function load(string $sid): ?array
    {
        $statement = null;

        try {
            // Narrowed through a separate variable so $statement -- which the
            // finally below dereferences -- is only ever PDOStatement|null.
            $prepared = $this->pdo->prepare("SELECT sess_data FROM {$this->table} WHERE sess_id = ?");
            if ($prepared === false) {
                return null;
            }
            $statement = $prepared;
            $statement->execute([$sid]);
            // fetch() rather than fetchColumn(): a LOB column comes back as a
            // stream on some drivers, and only the row form is typed loosely
            // enough to say so.
            $row = $statement->fetch(PDO::FETCH_NUM);
            $payload = is_array($row) && array_key_exists(0, $row) ? $row[0] : null;

            if (is_resource($payload)) {
                // Drain it while the cursor is still open.
                $contents = stream_get_contents($payload);
                $payload = $contents === false ? null : $contents;
            }
        } catch (PDOException $e) {
            throw new StorageException('Failed loading session row: ' . $e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            // Release the cursor. A fetched-but-unclosed statement keeps the
            // connection inside an implicit read transaction holding a shared
            // lock, which on SQLite blocks every *other* connection from
            // writing -- so in a worker runtime one worker's load() makes the
            // next worker's save() fail with SQLITE_BUSY. busy_timeout does not
            // cover the shared-to-exclusive upgrade.
            $statement?->closeCursor();
        }

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        return $this->decode($payload);
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $payload = $this->encode($data);

        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO {$this->table} (sess_id, sess_data, sess_time) VALUES (?, ?, CURRENT_TIMESTAMP) "
                . 'ON CONFLICT (sess_id) DO UPDATE SET sess_data = EXCLUDED.sess_data, sess_time = EXCLUDED.sess_time',
            );
            $statement->bindValue(1, $sid, PDO::PARAM_STR);
            $statement->bindValue(2, $payload, PDO::PARAM_LOB);
            $statement->execute();
        } catch (PDOException $e) {
            throw new StorageException('Failed writing session row: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    #[\Override]
    public function delete(string $sid): void
    {
        try {
            $this->pdo->prepare("DELETE FROM {$this->table} WHERE sess_id = ?")->execute([$sid]);
        } catch (PDOException) {
            // best-effort: a missing row (or a dead connection during shutdown) isn't worth failing the request over
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        if (function_exists('igbinary_serialize')) {
            try {
                $packed = igbinary_serialize($data);
                if (is_string($packed)) {
                    return $packed;
                }
            } catch (Throwable) {
                // fall through to JSON
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $payload): ?array
    {
        if (function_exists('igbinary_unserialize') && !str_starts_with($payload, '{') && !str_starts_with($payload, '[')) {
            try {
                $decoded = igbinary_unserialize($payload);
                return self::asSessionData($decoded);
            } catch (Throwable) {
                return null;
            }
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return self::asSessionData($decoded);
    }

    /**
     * Narrow a decoded payload to the string-keyed shape a session is. A JSON
     * list, or an igbinary payload holding one, decodes to integer keys: that is
     * not session data, and handing it back would make the caller's key lookups
     * silently miss.
     *
     * @return array<string, mixed>|null
     */
    private static function asSessionData(mixed $decoded): ?array
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

}
