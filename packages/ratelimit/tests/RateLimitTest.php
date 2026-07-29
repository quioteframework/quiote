<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Security\RateLimit\LoginThrottle;
use Quiote\Security\RateLimit\PdoRateLimiterStorage;
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

    public function testInvalidTableNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $storage = new PdoRateLimiterStorage($this->sqlitePdo(), 'bad name; DROP TABLE x');
        $storage->fetch('anything');
    }
}
