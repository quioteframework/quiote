<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Quiote\Queue\JobPayload;
use Quiote\Queue\Redis\RedisQueueDriver;
use Quiote\Test\Redis\RedisContainers;

#[Group('integration')]
final class RedisQueueDriverTest extends TestCase
{
    private Client $client;

    private RedisQueueDriver $driver;

    #[\Override]
    protected function setUp(): void
    {
        if (!RedisContainers::dockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }

        $this->client = new Client(RedisContainers::dsn());
        $this->client->flushdb();
        $this->driver = new RedisQueueDriver($this->client, 'test_queue');
    }

    public function testReserveReturnsNullWhenQueueIsEmpty(): void
    {
        $this->assertNull($this->driver->reserve());
    }

    public function testPushThenReserveReturnsTheJob(): void
    {
        $this->driver->push(new JobPayload(RedisQueueDriverTestJob::class, ['userId' => 5]));

        $reserved = $this->driver->reserve();

        $this->assertNotNull($reserved);
        $this->assertSame(RedisQueueDriverTestJob::class, $reserved->payload->jobClass);
        $this->assertSame(['userId' => 5], $reserved->payload->params);
        $this->assertSame(0, $reserved->payload->attempts);
    }

    public function testReserveDoesNotReturnAJobNotYetAvailable(): void
    {
        $this->driver->push(new JobPayload(RedisQueueDriverTestJob::class, [], 0, new DateTimeImmutable('+1 hour')));

        $this->assertNull($this->driver->reserve());
    }

    public function testReserveDoesNotReturnAnAlreadyReservedJob(): void
    {
        $this->driver->push(new JobPayload(RedisQueueDriverTestJob::class));

        $first = $this->driver->reserve();
        $this->assertNotNull($first);

        $this->assertNull($this->driver->reserve());
    }

    public function testAckRemovesTheJobPermanently(): void
    {
        $this->driver->push(new JobPayload(RedisQueueDriverTestJob::class));
        $reserved = $this->driver->reserve();
        $this->assertNotNull($reserved);

        $this->driver->ack($reserved);

        $this->assertNull($this->driver->reserve());
        $this->assertSame(0, $this->client->llen('test_queue:processing'));
    }

    public function testReleaseMakesTheJobAvailableAgainWithBumpedAttempts(): void
    {
        $this->driver->push(new JobPayload(RedisQueueDriverTestJob::class));
        $reserved = $this->driver->reserve();
        $this->assertNotNull($reserved);

        $this->driver->release($reserved, 0);

        $again = $this->driver->reserve();
        $this->assertNotNull($again);
        $this->assertSame(1, $again->payload->attempts);
    }

    public function testReleaseWithDelayIsNotImmediatelyAvailable(): void
    {
        $this->driver->push(new JobPayload(RedisQueueDriverTestJob::class));
        $reserved = $this->driver->reserve();
        $this->assertNotNull($reserved);

        $this->driver->release($reserved, 3600);

        $this->assertNull($this->driver->reserve());
    }

    public function testDiscardRemovesTheJobPermanently(): void
    {
        $this->driver->push(new JobPayload(RedisQueueDriverTestJob::class));
        $reserved = $this->driver->reserve();
        $this->assertNotNull($reserved);

        $this->driver->discard($reserved);

        $this->assertNull($this->driver->reserve());
        $this->assertSame(0, $this->client->llen('test_queue:processing'));
    }
}

final class RedisQueueDriverTestJob implements \Quiote\Queue\Job
{
    public function handle(): void
    {
    }
}
