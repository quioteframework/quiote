<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cache\RecordingCache;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Test\Cache\Psr16KeyRecordingCache;

final class RecordingCacheTest extends TestCase
{
    public function testGetOnAHitRecordsTheHitAndValue(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $inner->set('k', 'v');
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->get('k');

        $this->assertSame('v', $result);
        $effects = $ledger->all();
        $this->assertCount(1, $effects);
        $this->assertSame(EffectKind::Cache, $effects[0]->kind);
        $this->assertSame('get:k', $effects[0]->fingerprint);
        $this->assertSame(['hit' => true, 'value' => 'v'], $effects[0]->result);
    }

    public function testGetOnAMissRecordsTheMissDistinctlyFromAHit(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->get('missing', 'default-value');

        $this->assertSame('default-value', $result);
        $effects = $ledger->all();
        $this->assertCount(1, $effects);
        $this->assertSame(['hit' => false], $effects[0]->result);
    }

    public function testGetOnAStoredNullValueIsRecordedAsAHit(): void
    {
        // Distinguishing "stored null" from "miss" is the whole point of the
        // explicit hit/miss flag -- a cache that legitimately stores null
        // must not be replayed as a miss. Psr16KeyRecordingCache's own get()
        // uses `??`, which conflates a stored null with a miss, so a
        // correctly-behaving PSR-16 double is used here instead.
        $inner = new class implements \Psr\SimpleCache\CacheInterface {
            /** @var array<string, mixed> */
            private array $values = [];
            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
            }
            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool { $this->values[$key] = $value; return true; }
            public function delete(string $key): bool { unset($this->values[$key]); return true; }
            public function clear(): bool { $this->values = []; return true; }
            public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }
            /** @param iterable<array-key, mixed> $values */
            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { return true; }
            public function deleteMultiple(iterable $keys): bool { return true; }
            public function has(string $key): bool { return array_key_exists($key, $this->values); }
        };
        $inner->set('k', null);
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->get('k', 'default-value');

        $this->assertNull($result);
        $this->assertSame(['hit' => true, 'value' => null], $ledger->all()[0]->result);
    }

    public function testSetRecordsTheRealResultAndDoesNotAlterIt(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->set('k', 'v', 60);

        $this->assertTrue($result);
        $effects = $ledger->all();
        $this->assertCount(1, $effects);
        $this->assertSame('set:k', $effects[0]->fingerprint);
        $this->assertSame('v', $inner->get('k'));
    }

    public function testDeleteRecordsTheRealResult(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $inner->set('k', 'v');
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->delete('k');

        $this->assertTrue($result);
        $this->assertSame('delete:k', $ledger->all()[0]->fingerprint);
        $this->assertNull($inner->get('k'));
    }

    public function testHasRecordsTheRealResult(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $inner->set('k', 'v');
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $this->assertTrue($cache->has('k'));
        $this->assertFalse($cache->has('missing'));

        $effects = $ledger->all();
        $this->assertCount(2, $effects);
        $this->assertTrue($effects[0]->result);
        $this->assertFalse($effects[1]->result);
    }

    public function testClearRecordsUnderAFixedFingerprint(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $this->assertTrue($cache->clear());

        $this->assertSame('clear', $ledger->all()[0]->fingerprint);
    }

    /**
     * The key correctness property: a cache hit changes what a request does,
     * so two get() calls on the same key returning different values must be
     * recorded as two distinct ordered effects, not collapsed.
     */
    public function testTwoSequentialGetCallsOnTheSameKeyWithDifferentOutcomesRecordTwoOrderedEffects(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $cache->get('k'); // miss
        $inner->set('k', 'now-present');
        $cache->get('k'); // hit

        $effects = $ledger->all();
        $this->assertCount(2, $effects);
        $this->assertSame(['hit' => false], $effects[0]->result);
        $this->assertSame(['hit' => true, 'value' => 'now-present'], $effects[1]->result);
    }

    public function testGetMultipleRecordsOneEffectPerKey(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $inner->set('a', 1);
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->getMultiple(['a', 'b'], 'default');

        $this->assertSame(['a' => 1, 'b' => 'default'], $result);
        $effects = $ledger->all();
        $this->assertCount(2, $effects);
        $this->assertSame('get:a', $effects[0]->fingerprint);
        $this->assertSame('get:b', $effects[1]->fingerprint);
    }

    public function testSetMultipleRecordsOneEffectPerKeyAndReturnsTrueOnlyWhenAllSucceed(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->setMultiple(['a' => 1, 'b' => 2]);

        $this->assertTrue($result);
        $this->assertCount(2, $ledger->all());
        $this->assertSame(1, $inner->get('a'));
        $this->assertSame(2, $inner->get('b'));
    }

    public function testDeleteMultipleRecordsOneEffectPerKey(): void
    {
        $inner = new Psr16KeyRecordingCache();
        $inner->set('a', 1);
        $inner->set('b', 2);
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $result = $cache->deleteMultiple(['a', 'b']);

        $this->assertTrue($result);
        $this->assertCount(2, $ledger->all());
        $this->assertNull($inner->get('a'));
        $this->assertNull($inner->get('b'));
    }

    public function testAFailingInnerCacheDoesNotRecordAnEffectAndTheExceptionPropagates(): void
    {
        $inner = new class implements \Psr\SimpleCache\CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException('backend unavailable');
            }
            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool { return true; }
            public function delete(string $key): bool { return true; }
            public function clear(): bool { return true; }
            public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }
            /** @param iterable<array-key, mixed> $values */
            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { return true; }
            public function deleteMultiple(iterable $keys): bool { return true; }
            public function has(string $key): bool { return false; }
        };
        $ledger = new EffectLedger();
        $cache = new RecordingCache($inner, $ledger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backend unavailable');

        try {
            $cache->get('k');
        } finally {
            $this->assertSame([], $ledger->all());
        }
    }
}
