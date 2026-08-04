<?php

declare(strict_types=1);

namespace Quiote\Session\Pdo;

use JsonException;
use PDO;
use PDOException;
use Quiote\Exception\StorageException;
use Quiote\Session\SessionCodec;
use Quiote\Session\SessionCodecInterface;
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
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: true),
    ) {
        self::assertValidTableName($table);
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

        return $this->codec->decode($payload);
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    public function save(string $sid, array $data): void
    {
        $payload = $this->codec->encode($data);

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
        } catch (PDOException $e) {
            // Not worth failing the request over -- a missing row, or a connection already torn
            // down at shutdown, are both ordinary. But the row surviving means the session it
            // holds can still be loaded until it expires, which matters when this is a logout.
            \Quiote\Logging\Log::for($this)->error(
                '[PdoSessionPersistence] could not delete session row "' . $sid
                . '"; the session data survives: ' . $e->getMessage()
            );
        }
    }

}
