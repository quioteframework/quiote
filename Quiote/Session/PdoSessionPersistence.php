<?php

declare(strict_types=1);

namespace Quiote\Session;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use Quiote\Exception\StorageException;

/**
 * Default PDO-backed SessionPersistenceInterface implementation. Expects a table
 * with (at least) sess_id/sess_data/sess_time columns, matching the schema most
 * PHP session table conventions already use:
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
        $this->table = (string)($parameters['table'] ?? 'session');
    }

    private function loadStatement(): PDOStatement
    {
        return $this->loadStmt ??= $this->pdo->prepare("SELECT sess_data FROM {$this->table} WHERE sess_id = ?");
    }

    private function saveStatement(): PDOStatement
    {
        return $this->saveStmt ??= $this->pdo->prepare(
            "INSERT INTO {$this->table} (sess_id, sess_data, sess_time) VALUES (?, ?, NOW()) "
            . "ON CONFLICT (sess_id) DO UPDATE SET sess_data = EXCLUDED.sess_data, sess_time = EXCLUDED.sess_time"
        );
    }

    private function deleteStatement(): PDOStatement
    {
        return $this->deleteStmt ??= $this->pdo->prepare("DELETE FROM {$this->table} WHERE sess_id = ?");
    }

    public function load(string $sid): ?array
    {
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
                    $decoded = @igbinary_unserialize($blob);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                } catch (Throwable) {
                }
            }
            if ($looksLikeJson) {
                $decoded = json_decode($blob, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return null;
        } catch (PDOException $e) {
            throw new StorageException('Failed loading session row: ' . $e->getMessage(), (int)$e->getCode(), $e);
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
