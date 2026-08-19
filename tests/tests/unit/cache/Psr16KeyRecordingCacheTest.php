<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Test\Cache\Psr16KeyRecordingCache;

/**
 * This test double is used by several suites to assert on the keys a cache
 * consumer actually sends. get() has to tell a stored `null` apart from a
 * genuine miss the way a real PSR-16 cache does, or a consumer test relying
 * on this double could pass for the wrong reason.
 */
final class Psr16KeyRecordingCacheTest extends TestCase
{
    public function testGetReturnsTheDefaultOnAGenuineMiss(): void
    {
        $cache = new Psr16KeyRecordingCache();

        $this->assertSame('default', $cache->get('missing', 'default'));
        $this->assertNull($cache->get('missing'));
    }

    public function testGetReturnsAStoredNullRatherThanTheDefault(): void
    {
        $cache = new Psr16KeyRecordingCache();
        $cache->set('k', null);

        $this->assertNull($cache->get('k', 'default'));
    }

    public function testHasDistinguishesAStoredNullFromAMiss(): void
    {
        $cache = new Psr16KeyRecordingCache();
        $cache->set('k', null);

        $this->assertTrue($cache->has('k'));
        $this->assertFalse($cache->has('missing'));
    }

    public function testGetReturnsAStoredNonNullValue(): void
    {
        $cache = new Psr16KeyRecordingCache();
        $cache->set('k', 'value');

        $this->assertSame('value', $cache->get('k', 'default'));
    }

    public function testDeleteMakesASubsequentGetAMissAgain(): void
    {
        $cache = new Psr16KeyRecordingCache();
        $cache->set('k', null);

        $cache->delete('k');

        $this->assertSame('default', $cache->get('k', 'default'));
        $this->assertFalse($cache->has('k'));
    }

    public function testGetMultiplePreservesAStoredNullPerKey(): void
    {
        $cache = new Psr16KeyRecordingCache();
        $cache->set('a', null);
        $cache->set('b', 'value');

        $result = iterator_to_array($cache->getMultiple(['a', 'b', 'c'], 'default'));

        $this->assertSame(['a' => null, 'b' => 'value', 'c' => 'default'], $result);
    }

    public function testRecordsEveryKeySeenInOrder(): void
    {
        $cache = new Psr16KeyRecordingCache();

        $cache->set('a', 1);
        $cache->get('b');
        $cache->has('c');

        $this->assertSame(['a', 'b', 'c'], $cache->recordedKeys());
    }

    public function testFlagsAReservedCharacterAsIllegal(): void
    {
        $cache = new Psr16KeyRecordingCache();

        $cache->get('a{b}');
        $cache->get('legal-key');

        $this->assertSame(['a{b}'], $cache->illegalKeys());
    }

    public function testFlagsAnEmptyKeyAsIllegal(): void
    {
        $cache = new Psr16KeyRecordingCache();

        $cache->get('');

        $this->assertSame([''], $cache->illegalKeys());
    }

    public function testClearRemovesAllStoredValues(): void
    {
        $cache = new Psr16KeyRecordingCache();
        $cache->set('a', null);
        $cache->set('b', 'value');

        $cache->clear();

        $this->assertSame('default', $cache->get('a', 'default'));
        $this->assertSame('default', $cache->get('b', 'default'));
    }
}
