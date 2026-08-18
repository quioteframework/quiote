<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Queue\Job;
use Quiote\Queue\JobPayload;
use Quiote\Queue\QueueConfig;
use Quiote\Queue\QueueDriverInterface;
use Quiote\Queue\QueueManager;
use Quiote\Support\Clock\FrozenClock;

final class QueueManagerTestJob implements Job
{
    public function handle(): void
    {
    }
}

final class QueueManagerSpyDriver implements QueueDriverInterface
{
    /** @var list<JobPayload> */
    public array $pushed = [];

    public function push(JobPayload $payload): void
    {
        $this->pushed[] = $payload;
    }
}

final class QueueManagerTest extends TestCase
{
    private function manager(FrozenClock $clock): QueueManager
    {
        $container = new Container();
        $container->set(QueueManagerSpyDriver::class, new QueueManagerSpyDriver());

        return new QueueManager(
            $container,
            new QueueConfig(defaultDriver: QueueManagerSpyDriver::class, retryMaxAttempts: 3, retryBackoffSeconds: 5),
            $clock,
        );
    }

    public function testPushWithNoDelayLeavesAvailableAtNull(): void
    {
        $manager = $this->manager(new FrozenClock(1_700_000_000.0));

        $manager->push(QueueManagerTestJob::class, ['userId' => 5]);

        $driver = $manager->driver();
        $this->assertInstanceOf(QueueManagerSpyDriver::class, $driver);
        $this->assertCount(1, $driver->pushed);
        $this->assertNull($driver->pushed[0]->availableAt);
    }

    /**
     * A delayed push's availableAt is `now + delaySeconds`, computed from the
     * injected clock -- not the real system clock, which is what makes a
     * replay of this call deterministic.
     */
    public function testPushWithADelayComputesAvailableAtFromTheInjectedClock(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $manager = $this->manager($clock);

        $manager->push(QueueManagerTestJob::class, [], 3600);

        $driver = $manager->driver();
        $this->assertInstanceOf(QueueManagerSpyDriver::class, $driver);
        $availableAt = $driver->pushed[0]->availableAt;
        $this->assertNotNull($availableAt);
        $this->assertSame(1_700_000_000 + 3600, $availableAt->getTimestamp());
    }

    public function testPushWithAZeroDelayIsImmediatelyAvailable(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $manager = $this->manager($clock);

        $manager->push(QueueManagerTestJob::class, [], 0);

        $driver = $manager->driver();
        $this->assertInstanceOf(QueueManagerSpyDriver::class, $driver);
        $availableAt = $driver->pushed[0]->availableAt;
        $this->assertNotNull($availableAt);
        $this->assertSame(1_700_000_000, $availableAt->getTimestamp());
    }
}
