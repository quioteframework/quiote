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
        try {
            $statement = $this->pdo->prepare("SELECT sess_data FROM {$this->table} WHERE sess_id = ?");
            $statement->execute([$sid]);
            $payload = $statement->fetchColumn();
        } catch (PDOException $e) {
            throw new StorageException('Failed loading session row: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
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

    private function encode(array $data): string
    {
        if (function_exists('igbinary_serialize')) {
            try {
                return igbinary_serialize($data);
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
                return is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                return null;
            }
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
