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
    /**
     * The complete object graph a serialized limiter state can legitimately
     * contain, passed to `unserialize()` as an allow-list.
     *
     * `['allowed_classes' => true]` -- permitting every autoloadable class --
     * was wrong here even though this row is written by {@see save()} and never
     * by a caller: `fetch()` reads back from a table, and a table is reachable
     * by anything else holding that database (a second application, a
     * misgranted role, an injection flaw elsewhere). Deserialization runs
     * `__wakeup()`/`__destruct()` on whatever it materializes, so the
     * `instanceof` check one line below happens strictly *after* any gadget
     * would already have fired. The allow-list is what makes that check
     * meaningful rather than decorative.
     *
     * `Rate` and `DateTimeImmutable` are not limiter states themselves; they
     * are the nested values `TokenBucket` and `CalendarAlignedWindow` hold, and
     * omitting them would make those two states unrestorable (they come back as
     * `__PHP_Incomplete_Class` and fail the `instanceof`).
     *
     * @var list<class-string>
     */
    private const array ALLOWED_STATE_CLASSES = [
        \Symfony\Component\RateLimiter\Policy\Window::class,
        \Symfony\Component\RateLimiter\Policy\SlidingWindow::class,
        \Symfony\Component\RateLimiter\Policy\TokenBucket::class,
        \Symfony\Component\RateLimiter\Policy\CalendarAlignedWindow::class,
        \Symfony\Component\RateLimiter\Policy\Rate::class,
        \DateTimeImmutable::class,
    ];

    public function __construct(
        private \PDO $pdo,
        private string $table = 'quiote_rate_limit'
    ) {
    }

    /**
     * Writes the limiter state to the table, inserting or updating in one
     * statement.
     *
     * The state is serialized and base64-encoded into the TEXT column, and its
     * expiration time is stored as an absolute UNIX timestamp — a state with no
     * expiration time gets a NULL, which never expires. The row key is the
     * hashed limiter state id, not the id itself.
     */
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

    /**
     * Loads the stored limiter state for the given id, or null when there is
     * none to use.
     *
     * Null covers every unusable case, and the caller treats them all as "no
     * state yet": no row, a row whose stored expiry has passed (which is also
     * deleted on the way out), an unreadable or non-base64 payload, and a
     * payload that does not deserialize into a `LimiterStateInterface`.
     * Deserialization is restricted to {@see self::ALLOWED_STATE_CLASSES}, so a
     * row written by anything other than {@see self::save()} cannot instantiate
     * arbitrary classes.
     */
    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $id = $this->key($limiterStateId);
        $stmt = $this->pdo->prepare(
            sprintf('SELECT state, expires_at FROM %s WHERE id = :id', $this->quoteIdent($this->table))
        );
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ((is_int($expiresAt) || is_string($expiresAt)) && (int) $expiresAt < time()) {
            // Expired window — drop it and behave as if absent.
            $this->delete($limiterStateId);
            return null;
        }

        $state = $row['state'] ?? null;
        if (!is_string($state)) {
            return null;
        }
        $decoded = base64_decode($state, true);
        if ($decoded === false) {
            return null;
        }
        $value = @unserialize($decoded, ['allowed_classes' => self::ALLOWED_STATE_CLASSES]);
        return $value instanceof LimiterStateInterface ? $value : null;
    }

    /**
     * Removes the stored state for the given limiter id.
     *
     * Deleting an id with no row is not an error, so this is safe to call for a
     * limiter that has never been saved.
     */
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
