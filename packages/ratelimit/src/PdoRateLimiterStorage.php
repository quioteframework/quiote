<?php

namespace Quiote\Security\RateLimit;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * A symfony/rate-limiter StorageInterface backed by a relational database via PDO.
 * Lets rate-limiter / login-throttle state live in the application database
 * (Postgres) instead of Redis. The workload — a handful of writes per
 * authentication attempt — is well within what Postgres handles comfortably,
 * and it removes a moving part (and its hosting cost).
 * Storage is intentionally portable: the limiter state is serialized and stored
 * base64-encoded in a TEXT column, and expiry is a UNIX timestamp in an INTEGER
 * column, avoiding driver-specific BLOB/TIMESTAMP types. Upserts use
 * `INSERT ... ON CONFLICT` (PostgreSQL and SQLite ≥ 3.24).
 * Schema (see {@see self::schema()}):
 *   CREATE TABLE quiote_rate_limit (
 *       id         VARCHAR(64) PRIMARY KEY,
 *       state      TEXT        NOT NULL,
 *       expires_at INTEGER     NULL
 *   ); */
final readonly class PdoRateLimiterStorage implements StorageInterface
{
    public function __construct(
        private \PDO $pdo,
        private string $table = 'quiote_rate_limit'
    ) {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $id = $this->key($limiterState->getId());
        $blob = base64_encode(serialize($limiterState));
        $ttl = $limiterState->getExpirationTime();
        $expiresAt = ($ttl === null) ? null : (time() + $ttl);

        $sql = sprintf(
            'INSERT INTO %1$s (id, state, expires_at) VALUES (:id, :state, :exp)'
            . ' ON CONFLICT (id) DO UPDATE SET state = :state2, expires_at = :exp2',
            $this->quoteIdent($this->table)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':state', $blob);
        $stmt->bindValue(':state2', $blob);
        if ($expiresAt === null) {
            $stmt->bindValue(':exp', null, \PDO::PARAM_NULL);
            $stmt->bindValue(':exp2', null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':exp', $expiresAt, \PDO::PARAM_INT);
            $stmt->bindValue(':exp2', $expiresAt, \PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $id = $this->key($limiterStateId);
        $stmt = $this->pdo->prepare(
            sprintf('SELECT state, expires_at FROM %s WHERE id = :id', $this->quoteIdent($this->table))
        );
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $expiresAt = $row['expires_at'];
        if ($expiresAt !== null && (int) $expiresAt < time()) {
            // Expired window — drop it and behave as if absent.
            $this->delete($limiterStateId);
            return null;
        }

        $decoded = base64_decode((string) $row['state'], true);
        if ($decoded === false) {
            return null;
        }
        $value = @unserialize($decoded, ['allowed_classes' => true]);
        return $value instanceof LimiterStateInterface ? $value : null;
    }

    public function delete(string $limiterStateId): void
    {
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE id = :id', $this->quoteIdent($this->table))
        );
        $stmt->bindValue(':id', $this->key($limiterStateId));
        $stmt->execute();
    }

    /**
     * Remove expired rows. Safe to call from a periodic job; the per-row lazy
     * cleanup in fetch() handles correctness, this just reclaims space.
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare(
            sprintf('DELETE FROM %s WHERE expires_at IS NOT NULL AND expires_at < :now', $this->quoteIdent($this->table))
        );
        $stmt->bindValue(':now', time(), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * DDL to create the backing table (PostgreSQL / SQLite compatible).
     */
    public static function schema(string $table = 'quiote_rate_limit'): string
    {
        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
            . ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' state TEXT NOT NULL,'
            . ' expires_at INTEGER NULL'
            . ')',
            $table
        );
    }

    /** Bound, collision-free primary key derived from the limiter state id. */
    private function key(string $id): string
    {
        return sha1($id);
    }

    private function quoteIdent(string $ident): string
    {
        // Table name is developer-supplied config, not user input; still, only
        // allow a safe identifier shape to avoid any accidental injection.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
            throw new \InvalidArgumentException('Invalid rate-limit table name: ' . $ident);
        }
        return $ident;
    }
}
