<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Security\RateLimit\LoginThrottle;
use Quiote\Security\RateLimit\PdoRateLimiterStorage;
use Quiote\Support\Clock\FrozenClock;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

final class RateLimitTest extends TestCase
{
    private function sqlitePdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(PdoRateLimiterStorage::schema());
        return $pdo;
    }

    private function throttle(StorageInterface $storage, int $max = 3): LoginThrottle
    {
        return new LoginThrottle($storage, $max, '1 hour', 'test_throttle');
    }

    // --- LoginThrottle core behaviour (in-memory storage) ---

    public function testAllowsUpToLimitThenBlocks(): void
    {
        $t = $this->throttle(new InMemoryStorage(), 3);
        $key = 'ip-a';

        // Not blocked initially. Each call's result goes through a local: an
        // assertion on the call expression itself narrows that expression's type
        // for the rest of the method, which then makes the later
        // "now it is blocked" assertions look statically impossible.
        $initial = $t->retryAfter($key);
        $this->assertNull($initial);

        // Three failures are within the allowance.
        for ($i = 0; $i < 3; $i++) {
            $withinAllowance = $t->registerFailure($key);
            $this->assertNull($withinAllowance, 'failure ' . ($i + 1) . ' should be within the allowance');
        }

        // Now exhausted: a peek reports a wait, and a further failure is rejected.
        $retry = $t->retryAfter($key);
        $this->assertNotNull($retry);
        $this->assertGreaterThan(0, $retry);

        $rejected = $t->registerFailure($key);
        $this->assertNotNull($rejected);
        $this->assertGreaterThan(0, $rejected);
    }

    /**
     * secondsUntil() is `$retryAt - now`, so its value has to shrink in exact
     * lockstep with the injected clock advancing -- the retry-at instant
     * itself is fixed the moment the limit was exhausted.
     */
    public function testRetryAfterShrinksExactlyAsTheInjectedClockAdvances(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $t = new LoginThrottle(new InMemoryStorage(), 1, '1 hour', 'test_throttle_clock', $clock);
        $key = 'ip-clock';

        $t->registerFailure($key);
        $retryBefore = $t->retryAfter($key);
        $this->assertNotNull($retryBefore);

        $clock->advance(10.0);
        $retryAfter = $t->retryAfter($key);
        $this->assertNotNull($retryAfter);

        $this->assertSame($retryBefore - 10, $retryAfter);
    }

    public function testResetClearsCounter(): void
    {
        $t = $this->throttle(new InMemoryStorage(), 3);
        $key = 'ip-b';

        $t->registerFailure($key);
        $t->registerFailure($key);
        $t->registerFailure($key);
        $this->assertNotNull($t->retryAfter($key), 'should be blocked after limit');

        $t->reset($key);
        $this->assertNull($t->retryAfter($key), 'reset must clear the block (e.g. after a successful login)');
    }

    public function testKeysAreIsolated(): void
    {
        $t = $this->throttle(new InMemoryStorage(), 2);
        $t->registerFailure('ip-x');
        $t->registerFailure('ip-x');
        $this->assertNotNull($t->retryAfter('ip-x'));
        $this->assertNull($t->retryAfter('ip-y'), 'a different key must not be affected');
    }

    // --- PdoRateLimiterStorage ---

    public function testPdoStorageRoundtripsThroughThrottle(): void
    {
        $storage = new PdoRateLimiterStorage($this->sqlitePdo());
        $t = $this->throttle($storage, 3);
        $key = 'ip-pdo';

        $this->assertNull($t->retryAfter($key));
        $t->registerFailure($key);
        $t->registerFailure($key);
        $t->registerFailure($key);
        $this->assertNotNull($t->retryAfter($key), 'PDO-backed throttle must block after the limit');

        $t->reset($key);
        $this->assertNull($t->retryAfter($key));
    }

    public function testPdoStoragePersistsAcrossInstances(): void
    {
        $pdo = $this->sqlitePdo();
        $key = 'ip-persist';

        $t1 = $this->throttle(new PdoRateLimiterStorage($pdo), 2);
        $t1->registerFailure($key);
        $t1->registerFailure($key);

        // A fresh throttle/storage over the SAME connection sees the state.
        $t2 = $this->throttle(new PdoRateLimiterStorage($pdo), 2);
        $this->assertNotNull($t2->retryAfter($key), 'state must persist in the database, not just in memory');
    }

    private function countRows(\PDO $pdo): int
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM quiote_rate_limit');
        $this->assertNotFalse($statement);
        return (int) $statement->fetchColumn();
    }

    public function testPdoPurgeExpiredRemovesStaleRows(): void
    {
        $pdo = $this->sqlitePdo();
        $storage = new PdoRateLimiterStorage($pdo);
        $t = $this->throttle($storage, 3);
        $t->registerFailure('ip-gc');
        $this->assertSame(1, $this->countRows($pdo));

        // Force the row to be expired, then purge.
        $pdo->exec('UPDATE quiote_rate_limit SET expires_at = ' . (time() - 10));
        $deleted = $storage->purgeExpired();
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->countRows($pdo));
    }

    /**
     * expires_at is wall-clock (compared against "now" by whatever process's
     * fetch()/purgeExpired() runs next), so save()/fetch()/purgeExpired() all
     * derive it from the injected clock rather than the real system clock.
     */
    public function testExpiryIsComputedFromTheInjectedClock(): void
    {
        $pdo = $this->sqlitePdo();
        $clock = new FrozenClock(1_700_000_000.0);
        $storage = new PdoRateLimiterStorage($pdo, clock: $clock);
        $t = $this->throttle($storage, 3);
        $t->registerFailure('ip-clock');

        $stmt = $pdo->query('SELECT expires_at FROM quiote_rate_limit');
        $this->assertNotFalse($stmt);
        $expiresAt = (int) $stmt->fetchColumn();
        // Stamped relative to the injected "now", not the real clock -- the exact TTL
        // is symfony/rate-limiter's own internal choice for a sliding window (larger
        // than the configured interval, since it needs the previous window too), so
        // this only asserts it is anchored to 1_700_000_000 at all, not a fixed offset.
        $this->assertGreaterThan(1_700_000_000, $expiresAt);

        // purgeExpired() compares that stored expiry against the injected clock, not
        // the real one: not yet due for purge one second before the window closes...
        $clock->set((float) $expiresAt - 1.0);
        $this->assertSame(0, $storage->purgeExpired());
        $this->assertSame(1, $this->countRows($pdo));

        // ...and purged once the clock passes it (purgeExpired() uses strict "<").
        $clock->set((float) $expiresAt + 1.0);
        $this->assertSame(1, $storage->purgeExpired());
        $this->assertSame(0, $this->countRows($pdo));
    }

    /**
     * `fetch()` deserializes a value read back from a table, and a table is
     * reachable by anything else holding that database. With
     * `['allowed_classes' => true]` any autoloadable class could be
     * materialized, and its `__wakeup()`/`__destruct()` would run *before* the
     * `instanceof LimiterStateInterface` check could reject it -- which is what
     * makes an allow-list, rather than that check, the thing doing the work.
     */
    public function testFetchRefusesToInstantiateAClassOutsideTheAllowList(): void
    {
        $pdo = $this->sqlitePdo();
        $storage = new PdoRateLimiterStorage($pdo);

        $planted = new RateLimitDeserializationCanary();
        $this->plantState($pdo, 'canary-key', $planted);

        RateLimitDeserializationCanary::$awoken = 0;
        $fetched = $storage->fetch('canary-key');

        $this->assertNull($fetched, 'a non-limiter payload must not be handed back');
        $this->assertSame(0, RateLimitDeserializationCanary::$awoken, '__wakeup() must never have run');
    }

    /** The other half: legitimate limiter states must still round-trip. */
    public function testFetchStillRestoresAllowedLimiterStates(): void
    {
        $pdo = $this->sqlitePdo();
        $storage = new PdoRateLimiterStorage($pdo);

        $state = new \Symfony\Component\RateLimiter\Policy\SlidingWindow('allowed-key', 3600);
        $state->add(1);
        $storage->save($state);

        $fetched = $storage->fetch('allowed-key');

        $this->assertInstanceOf(\Symfony\Component\RateLimiter\Policy\SlidingWindow::class, $fetched);
        $this->assertSame('allowed-key', $fetched->getId());
    }

    /** Writes a raw serialized payload the way save() would, bypassing its typing. */
    private function plantState(\PDO $pdo, string $id, object $value): void
    {
        $statement = $pdo->prepare('INSERT INTO quiote_rate_limit (id, state, expires_at) VALUES (:id, :state, NULL)');
        $statement->execute([
            'id' => sha1($id),
            'state' => base64_encode(serialize($value)),
        ]);
    }

    public function testInvalidTableNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $storage = new PdoRateLimiterStorage($this->sqlitePdo(), 'bad name; DROP TABLE x');
        $storage->fetch('anything');
    }
}

/**
 * Stands in for a deserialization gadget: it records whether unserialize()
 * reached its wake-up hook. A real gadget chain would do something useful to an
 * attacker there; the assertion is simply that the hook never runs.
 */
final class RateLimitDeserializationCanary
{
    public static int $awoken = 0;

    public function __wakeup(): void
    {
        self::$awoken++;
    }
}
