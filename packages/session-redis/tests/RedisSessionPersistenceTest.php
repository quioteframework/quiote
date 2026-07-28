<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Quiote\Session\Redis\RedisSessionPersistence;
use Quiote\Test\Redis\RedisContainers;

#[Group('integration')]
final class RedisSessionPersistenceTest extends TestCase
{
    private Client $client;

    private RedisSessionPersistence $persistence;

    #[\Override]
    protected function setUp(): void
    {
        if (!RedisContainers::dockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }

        $this->client = new Client(RedisContainers::dsn());
        $this->client->flushdb();
        $this->persistence = new RedisSessionPersistence($this->client, 'test_session:');
    }

    public function testLoadUnknownSessionReturnsNull(): void
    {
        $this->assertNull($this->persistence->load('missing'));
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        $this->persistence->save('sid-1', ['user_id' => 42, 'flash' => ['ok']]);

        $this->assertSame(['user_id' => 42, 'flash' => ['ok']], $this->persistence->load('sid-1'));
    }

    public function testSaveTwiceUpdatesInPlace(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->save('sid-1', ['a' => 2]);

        $this->assertSame(['a' => 2], $this->persistence->load('sid-1'));
    }

    public function testDeleteRemovesKey(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('sid-1');

        $this->assertNull($this->persistence->load('sid-1'));
    }

    public function testDeleteOfMissingKeyIsBestEffort(): void
    {
        $this->persistence->delete('never-existed');
        $this->addToAssertionCount(1);
    }

    public function testSavedKeyCarriesTheConfiguredTtl(): void
    {
        $persistence = new RedisSessionPersistence($this->client, 'test_session:', ttl: 60);
        $persistence->save('sid-1', ['a' => 1]);

        $ttl = $this->client->ttl('test_session:sid-1');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }
}
