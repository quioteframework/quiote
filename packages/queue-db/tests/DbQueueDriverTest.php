<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Queue\Db\DbQueueDriver;
use Quiote\Queue\Job;
use Quiote\Queue\JobPayload;

final class DbQueueDriverTestJob implements Job
{
    public function handle(): void
    {
    }
}

final class DbQueueDriverTest extends TestCase
{
    private function sqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(DbQueueDriver::schema());
        return $pdo;
    }

    public function testReserveReturnsNullWhenQueueIsEmpty(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());

        $this->assertNull($driver->reserve());
    }

    public function testPushThenReserveReturnsTheJob(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());
        $driver->push(new JobPayload(DbQueueDriverTestJob::class, ['userId' => 5]));

        $reserved = $driver->reserve();

        $this->assertNotNull($reserved);
        $this->assertSame(DbQueueDriverTestJob::class, $reserved->payload->jobClass);
        $this->assertSame(['userId' => 5], $reserved->payload->params);
        $this->assertSame(0, $reserved->payload->attempts);
    }

    public function testReserveDoesNotReturnAJobNotYetAvailable(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());
        $driver->push(new JobPayload(DbQueueDriverTestJob::class, [], 0, new DateTimeImmutable('+1 hour')));

        $this->assertNull($driver->reserve());
    }

    public function testReserveDoesNotReturnAnAlreadyReservedJob(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));

        $first = $driver->reserve();
        $this->assertNotNull($first);

        $this->assertNull($driver->reserve());
    }

    public function testAckRemovesTheJobPermanently(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));
        $reserved = $driver->reserve();
        $this->assertNotNull($reserved);

        $driver->ack($reserved);

        // Not returned again even after "expiring" (no visibility-timeout re-queue).
        $this->assertNull($driver->reserve());
    }

    public function testReleaseMakesTheJobAvailableAgainWithBumpedAttempts(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));
        $reserved = $driver->reserve();
        $this->assertNotNull($reserved);

        $driver->release($reserved, 0);

        $again = $driver->reserve();
        $this->assertNotNull($again);
        $this->assertSame(1, $again->payload->attempts);
    }

    public function testReleaseWithDelayIsNotImmediatelyAvailable(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));
        $reserved = $driver->reserve();
        $this->assertNotNull($reserved);

        $driver->release($reserved, 3600);

        $this->assertNull($driver->reserve());
    }

    public function testDiscardRemovesTheJobPermanently(): void
    {
        $driver = new DbQueueDriver($this->sqlitePdo());
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));
        $reserved = $driver->reserve();
        $this->assertNotNull($reserved);

        $driver->discard($reserved);

        $this->assertNull($driver->reserve());
    }

    public function testRejectsAnUnsafeTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $driver = new DbQueueDriver($this->sqlitePdo(), 'jobs; DROP TABLE jobs');
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));
    }
}
