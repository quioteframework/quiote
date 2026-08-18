<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
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

    public function testGetThrowsOnANoMatchingRecordedEffect(): void
    {
        $cache = new StubbedCache(new EffectLedger());

        $this->expectException(\RuntimeException::class);
        $cache->get('k');
    }

    public function testHasReturnsTheRecordedBoolean(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Cache, 'has:k', ['op' => 'has', 'key' => 'k'], true),
        ]);
        $cache = new StubbedCache($ledger);

        $this->assertTrue($cache->has('k'));
    }

    public function testHasThrowsOnANoMatchingRecordedEffect(): void
    {
        $cache = new StubbedCache(new EffectLedger());

        $this->expectException(\RuntimeException::class);
        $cache->has('k');
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
}
