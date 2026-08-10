<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Session\Redis\RedisSessionPersistence;
use Quiote\Session\SessionCodec;
use Quiote\Session\SessionCodecInterface;
use Quiote\Test\Redis\InMemoryPredisClient;

/**
 * RedisSessionPersistence against an in-memory Predis client: key naming,
 * TTL, codec delegation and the unreadable-payload paths, none of which need
 * a real Redis server. RedisSessionPersistenceTest covers the same surface
 * against a container and is #[Group('integration')].
 */
final class RedisSessionPersistenceUnitTest extends TestCase
{
    private InMemoryPredisClient $redis;

    private RedisSessionPersistence $persistence;

    #[\Override]
    protected function setUp(): void
    {
        $this->redis = new InMemoryPredisClient();
        $this->persistence = new RedisSessionPersistence($this->redis, 'test_session:');
    }

    public function testLoadOfUnknownSessionReturnsNull(): void
    {
        $this->assertNull($this->persistence->load('missing'));
    }

    public function testSaveThenLoadRoundTripsThePayload(): void
    {
        $this->persistence->save('sid-1', ['user_id' => 42, 'flash' => ['ok']]);

        $this->assertSame(['user_id' => 42, 'flash' => ['ok']], $this->persistence->load('sid-1'));
    }

    public function testSaveStoresUnderThePrefixedKey(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);

        $this->assertSame(['test_session:sid-1'], $this->redis->keys());
    }

    public function testAnEmptyPrefixStoresUnderTheBareSessionId(): void
    {
        $persistence = new RedisSessionPersistence($this->redis, '');
        $persistence->save('sid-1', ['a' => 1]);

        $this->assertSame(['sid-1'], $this->redis->keys());
    }

    public function testTwoBackendsWithDifferentPrefixesDoNotSeeEachOther(): void
    {
        $mine = new RedisSessionPersistence($this->redis, 'app_a:');
        $theirs = new RedisSessionPersistence($this->redis, 'app_b:');

        $mine->save('sid-1', ['owner' => 'a']);

        $this->assertSame(['owner' => 'a'], $mine->load('sid-1'));
        $this->assertNull($theirs->load('sid-1'));
    }

    public function testSaveTwiceUpdatesInPlace(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->save('sid-1', ['a' => 2]);

        $this->assertSame(['a' => 2], $this->persistence->load('sid-1'));
    }

    public function testSaveOfAnEmptyPayloadRoundTripsAsAnEmptyArray(): void
    {
        $this->persistence->save('sid-1', []);

        $this->assertSame([], $this->persistence->load('sid-1'));
    }

    public function testDeleteRemovesTheKey(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('sid-1');

        $this->assertNull($this->persistence->load('sid-1'));
        $this->assertSame([], $this->redis->keys());
    }

    public function testDeleteOfAMissingKeyIsANoOp(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('never-existed');

        $this->assertSame(['a' => 1], $this->persistence->load('sid-1'));
    }

    public function testSaveWritesTheKeyWithTheConfiguredTtl(): void
    {
        $persistence = new RedisSessionPersistence($this->redis, 'test_session:', ttl: 60);
        $persistence->save('sid-1', ['a' => 1]);

        $this->assertSame(60, $this->redis->ttl('test_session:sid-1'));
    }

    public function testTheDefaultTtlIsTheStandardSessionLifetime(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);

        $this->assertSame(1440, $this->redis->ttl('test_session:sid-1'));
    }

    public function testASessionIsGoneOnceRedisExpiresIt(): void
    {
        $persistence = new RedisSessionPersistence($this->redis, 'test_session:', ttl: 60);
        $persistence->save('sid-1', ['a' => 1]);

        $this->redis->advanceTime(59);
        $this->assertSame(['a' => 1], $persistence->load('sid-1'), 'still inside the ttl');

        $this->redis->advanceTime(2);
        $this->assertNull($persistence->load('sid-1'), 'expired, so absent -- no GC pass involved');
    }

    public function testSaveRefreshesTheTtlOfAnExistingSession(): void
    {
        $persistence = new RedisSessionPersistence($this->redis, 'test_session:', ttl: 60);
        $persistence->save('sid-1', ['a' => 1]);

        $this->redis->advanceTime(50);
        $persistence->save('sid-1', ['a' => 2]);

        $this->assertSame(60, $this->redis->ttl('test_session:sid-1'));
    }

    public function testSaveUsesSetexSoRedisOwnsExpiry(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);

        $this->assertSame(['SETEX'], $this->redis->commandLog());
    }

    public function testAnEmptyStoredPayloadReadsAsNoSession(): void
    {
        $this->redis->set('test_session:sid-1', '');

        $this->assertNull($this->persistence->load('sid-1'));
    }

    public function testAnUndecodablePayloadReadsAsNoSession(): void
    {
        $this->redis->set('test_session:sid-1', 'not a session payload');

        $this->assertNull($this->persistence->load('sid-1'));
    }

    public function testTheCodecOwnsTheStoredForm(): void
    {
        $persistence = new RedisSessionPersistence(
            $this->redis,
            'test_session:',
            codec: new class implements SessionCodecInterface {
                #[\Override]
                public function encode(array $data): string
                {
                    return 'rot13:' . str_rot13(json_encode($data, JSON_THROW_ON_ERROR));
                }

                #[\Override]
                public function decode(string $payload): ?array
                {
                    if (!str_starts_with($payload, 'rot13:')) {
                        return null;
                    }

                    /** @var array<string, mixed> $decoded */
                    $decoded = json_decode(str_rot13(substr($payload, 6)), true, flags: JSON_THROW_ON_ERROR);

                    return $decoded;
                }
            },
        );

        $persistence->save('sid-1', ['a' => 1]);

        $this->assertStringStartsWith('rot13:', (string) $this->redis->get('test_session:sid-1'));
        $this->assertSame(['a' => 1], $persistence->load('sid-1'));
    }

    public function testAPayloadWrittenByAJsonCodecIsReadableByTheBinaryPreferringDefault(): void
    {
        $writer = new RedisSessionPersistence($this->redis, 'test_session:', codec: SessionCodec::portable());
        $writer->save('sid-1', ['a' => 1]);

        $this->assertSame(['a' => 1], $this->persistence->load('sid-1'));
    }
}
