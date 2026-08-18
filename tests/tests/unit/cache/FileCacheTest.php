<?php

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;
use Quiote\Cache\FileCache;
use Quiote\Support\Clock\FrozenClock;

/**
 * PSR-16 conformance and payload-handling guarantees for the dependency-free
 * file cache.
 */
class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/quiote-filecache-test-' . bin2hex(random_bytes(6));
        $this->cache = new FileCache($this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testRoundTrip(): void
    {
        $this->assertTrue($this->cache->set('greeting', 'hello'));
        $this->assertSame('hello', $this->cache->get('greeting'));
        $this->assertTrue($this->cache->has('greeting'));
    }

    public function testMissReturnsTheDefault(): void
    {
        $this->assertSame('fallback', $this->cache->get('absent', 'fallback'));
        $this->assertFalse($this->cache->has('absent'));
    }

    /**
     * PSR-16: a stored null reads back as null. It used to be indistinguishable
     * from a miss, so get() answered with the caller's default while has()
     * simultaneously reported the entry as present.
     */
    public function testAStoredNullIsNotAMiss(): void
    {
        $this->cache->set('nothing', null);

        $this->assertTrue($this->cache->has('nothing'));
        $this->assertNull($this->cache->get('nothing', 'DEFAULT'));
    }

    public function testAStoredFalseIsNotAMiss(): void
    {
        $this->cache->set('flag', false);

        $this->assertTrue($this->cache->has('flag'));
        $this->assertFalse($this->cache->get('flag', 'DEFAULT'));
    }

    /** PSR-16: a zero or negative TTL means the item is already expired. */
    public function testZeroTtlExpiresImmediately(): void
    {
        $this->cache->set('zero', 'v', 0);

        $this->assertFalse($this->cache->has('zero'));
        $this->assertSame('MISS', $this->cache->get('zero', 'MISS'));
    }

    public function testNegativeTtlExpiresImmediately(): void
    {
        $this->cache->set('neg', 'v', -10);

        $this->assertSame('MISS', $this->cache->get('neg', 'MISS'));
    }

    public function testZeroTtlOverwritingAnExistingEntryRemovesIt(): void
    {
        $this->cache->set('k', 'original');
        $this->cache->set('k', 'replacement', 0);

        $this->assertSame('MISS', $this->cache->get('k', 'MISS'));
    }

    public function testNullTtlNeverExpires(): void
    {
        $this->cache->set('forever', 'v', null);

        $this->assertSame('v', $this->cache->get('forever'));
    }

    public function testDateIntervalTtlIsHonoured(): void
    {
        $this->cache->set('interval', 'v', new \DateInterval('PT60S'));

        $this->assertSame('v', $this->cache->get('interval'));
    }

    /**
     * @return list<array{string}>
     */
    public static function reservedKeyProvider(): array
    {
        return [['a{b'], ['a}b'], ['a(b'], ['a)b'], ['a/b'], ['a\\b'], ['a@b'], ['a:b']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedKeyProvider')]
    public function testReservedCharactersInAKeyAreRejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set($key, 'v');
    }

    public function testAnEmptyKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get('');
    }

    public function testALegalKeyWithPunctuationIsAccepted(): void
    {
        $this->cache->set('a.b_c-d123', 'v');

        $this->assertSame('v', $this->cache->get('a.b_c-d123'));
    }

    /**
     * A cache file is attacker-controlled as soon as anything can write to the
     * directory, so decoding must not instantiate whatever class the payload
     * names -- that runs its __wakeup()/__destruct().
     */
    public function testAPayloadNamingAClassDoesNotInstantiateIt(): void
    {
        $this->cache->set('poison', 'placeholder');
        $file = glob($this->dir . '/*.cache')[0] ?? null;
        $this->assertNotNull($file);
        file_put_contents($file, "0\n" . serialize(new ArrayObject(['x'])));

        $value = $this->cache->get('poison');

        $this->assertNotInstanceOf(ArrayObject::class, $value);
    }

    public function testTheCacheDirectoryIsNotWorldWritable(): void
    {
        $mode = fileperms($this->dir) & 0777;

        $this->assertSame(0, $mode & 0o022, sprintf('cache directory is group/world writable (%04o)', $mode));
    }

    public function testDeleteRemovesTheEntry(): void
    {
        $this->cache->set('k', 'v');

        $this->assertTrue($this->cache->delete('k'));
        $this->assertFalse($this->cache->has('k'));
    }

    public function testDeletingAnAbsentKeySucceeds(): void
    {
        $this->assertTrue($this->cache->delete('never-existed'));
    }

    public function testClearEmptiesTheCache(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->assertTrue($this->cache->clear());
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function testMultipleOperations(): void
    {
        $this->assertTrue($this->cache->setMultiple(['x' => 1, 'y' => 2]));
        $this->assertSame(['x' => 1, 'y' => 2], iterator_to_array($this->cache->getMultiple(['x', 'y'])));

        $this->assertTrue($this->cache->deleteMultiple(['x', 'y']));
        $this->assertSame(
            ['x' => 'MISS', 'y' => 'MISS'],
            iterator_to_array($this->cache->getMultiple(['x', 'y'], 'MISS')),
        );
    }

    public function testArraysRoundTrip(): void
    {
        $this->cache->set('list', ['a' => 1, 'b' => [2, 3]]);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $this->cache->get('list'));
    }

    public function testACorruptPayloadReadsAsAMiss(): void
    {
        $this->cache->set('broken', 'v');
        $file = glob($this->dir . '/*.cache')[0] ?? null;
        $this->assertNotNull($file);
        file_put_contents($file, "0\nnot-a-serialized-value");

        $this->assertSame('MISS', $this->cache->get('broken', 'MISS'));
        $this->assertFalse($this->cache->has('broken'));
    }

    public function testAHeaderlessPayloadReadsAsAMiss(): void
    {
        $this->cache->set('headerless', 'v');
        $file = glob($this->dir . '/*.cache')[0] ?? null;
        $this->assertNotNull($file);
        file_put_contents($file, 'no-newline-at-all');

        $this->assertSame('MISS', $this->cache->get('headerless', 'MISS'));
    }

    // -- injected clock -------------------------------------------------------
    //
    // TTL expiry above is only exercised at 0/negative TTLs, since a positive
    // one would need a real sleep() to observe expiring. A FrozenClock makes a
    // real elapsed-time expiry deterministic instead.

    public function testAPositiveTtlExpiresOnceTheInjectedClockPassesIt(): void
    {
        $clock = new FrozenClock(1_000_000.0);
        $cache = new FileCache($this->dir, $clock);
        $cache->set('k', 'v', 60);

        $clock->advance(59.0);
        $this->assertSame('v', $cache->get('k'));

        $clock->advance(2.0);
        $this->assertSame('MISS', $cache->get('k', 'MISS'));
        $this->assertFalse($cache->has('k'));
    }

    public function testADateIntervalTtlIsResolvedAgainstTheInjectedClock(): void
    {
        $clock = new FrozenClock(1_000_000.0);
        $cache = new FileCache($this->dir, $clock);
        $cache->set('k', 'v', new \DateInterval('PT60S'));

        $clock->advance(61.0);

        $this->assertSame('MISS', $cache->get('k', 'MISS'));
    }
}
