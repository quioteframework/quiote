<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cache\CacheFingerprint;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\StubbedCache;

final class StubbedCacheTest extends TestCase
{
    public function testGetReturnsTheRecordedValueOnAHit(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'get:k', ['op' => 'get', 'key' => 'k'], ['hit' => true, 'value' => 'v']),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertSame('v', $cache->get('k'));
    }

    public function testGetReturnsTheCallersOwnDefaultOnARecordedMiss(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'get:k', ['op' => 'get', 'key' => 'k'], ['hit' => false]),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertSame('replay-time-default', $cache->get('k', 'replay-time-default'));
    }

    public function testGetReturnsTheRecordedNullValueRatherThanTheDefault(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'get:k', ['op' => 'get', 'key' => 'k'], ['hit' => true, 'value' => null]),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertNull($cache->get('k', 'should-not-see-this'));
    }


    public function testHasReturnsTheRecordedBoolean(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'has:k', ['op' => 'has', 'key' => 'k'], true),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertTrue($cache->has('k'));
    }


    public function testTwoIdenticalGetCallsMatchTwoRecordedEffectsInOrder(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'get:k', [], ['hit' => false]),
            new Effect(1, EffectKind::Cache, 'get:k', [], ['hit' => true, 'value' => 'now-present']),
        ]);
        $cache = new StubbedCache($ledger);

        $first = $cache->get('k', 'default');
        $second = $cache->get('k', 'default');

        $this->assertSame('default', $first);
        $this->assertSame('now-present', $second);
    }

    public function testSetReproducesTheRecordedBooleanWhenAMatchExists(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'set:k', ['op' => 'set', 'key' => 'k'], false),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertFalse($cache->set('k', 'v'));
    }

    public function testSetDefaultsToTrueWhenNoRecordedEffectExists(): void
    {
        $cache = new StubbedCache(new EffectLedger());

        $this->assertTrue($cache->set('k', 'v'));
    }

    public function testDeleteDefaultsToTrueWhenNoRecordedEffectExists(): void
    {
        $cache = new StubbedCache(new EffectLedger());

        $this->assertTrue($cache->delete('k'));
    }

    public function testClearReproducesTheRecordedBooleanWhenAMatchExists(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'clear', ['op' => 'clear'], false),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertFalse($cache->clear());
    }

    public function testGetMultipleMatchesOneEffectPerKey(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'get:a', [], ['hit' => true, 'value' => 1]),
            new Effect(1, EffectKind::Cache, 'get:b', [], ['hit' => false]),
        ]);
        $cache = new StubbedCache($ledger);

        $result = $cache->getMultiple(['a', 'b'], 'default');

        $this->assertSame(['a' => 1, 'b' => 'default'], $result);
    }

    public function testSetMultipleReturnsTrueWhenNothingWasRecorded(): void
    {
        $cache = new StubbedCache(new EffectLedger());

        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
    }

    public function testDeleteMultipleReturnsTrueWhenNothingWasRecorded(): void
    {
        $cache = new StubbedCache(new EffectLedger());

        $this->assertTrue($cache->deleteMultiple(['a', 'b']));
    }

    public function testAnUnrecordedGetReturnsTheCallersDefaultAsPsr16Requires(): void
    {
        // PSR-16's get() must return $default on a miss and may only throw for an invalid key, and
        // Quiote\Cache\CacheInterface extends it -- so throwing here broke the contract in exactly
        // the way a substituted implementation must not.
        $cache = new StubbedCache(new EffectLedger());

        $this->assertSame('fallback', $cache->get('orders.42', 'fallback'));
        $this->assertNull($cache->get('orders.43'));
    }

    public function testAnUnrecordedGetIsStillReportedRatherThanSilentlyAnswered(): void
    {
        // The intent behind the old throw was right: an isolated replay answering a read it has no
        // recording for fabricates a passing test. The information moves somewhere a test asserts
        // on rather than being dropped.
        $cache = new StubbedCache(new EffectLedger());

        $cache->get('orders.42');
        $cache->has('orders.43');

        $this->assertSame(['get("orders.42")', 'has("orders.43")'], $cache->unrecordedReads());
    }

    public function testAnUnrecordedHasReturnsFalseRatherThanThrowing(): void
    {
        $cache = new StubbedCache(new EffectLedger());

        $this->assertFalse($cache->has('orders.42'));
    }

    public function testAMalformedRecordedReadIsReportedAndFallsBackToTheDefault(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, CacheFingerprint::of('get', 'k'), ['op' => 'get', 'key' => 'k'], 'not-a-read'),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertSame('fallback', $cache->get('k', 'fallback'));
        $this->assertSame(['get("k") [malformed recorded effect]'], $cache->unrecordedReads());
    }

    public function testARecordedReadIsNotReportedAsUnrecorded(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, CacheFingerprint::of('get', 'k'), ['op' => 'get', 'key' => 'k'], ['hit' => true, 'value' => 'v']),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertSame('v', $cache->get('k'));
        $this->assertSame([], $cache->unrecordedReads());
    }
}
