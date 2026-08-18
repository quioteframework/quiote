<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Queue\Db\DbQueueDriver;
use Quiote\Queue\Job;
use Quiote\Queue\JobPayload;
use Quiote\Support\Clock\FrozenClock;
use Quiote\Support\Random\SeededRandomness;

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

    /**
     * available_at is wall-clock (it's compared against "now" by a
     * different worker process's reserve() call entirely), so it has to be
     * derived from the injected clock rather than the real system clock.
     */
    public function testPushWithNoDelayStoresAvailableAtFromTheInjectedClock(): void
    {
        $pdo = $this->sqlitePdo();
        $clock = new FrozenClock(1_700_000_000.0);
        $driver = new DbQueueDriver($pdo, clock: $clock);

        $driver->push(new JobPayload(DbQueueDriverTestJob::class));

        $stmt = $pdo->query('SELECT available_at FROM quiote_queue_jobs');
        $this->assertNotFalse($stmt);
        $this->assertSame(1_700_000_000, (int) $stmt->fetchColumn());
    }

    /**
     * release()'s delay is computed from the injected clock too, so a job
     * released with a delay becomes due exactly when the clock says it
     * should, not whenever the real system clock happens to reach that point.
     */
    public function testReleaseWithDelayBecomesDueExactlyWhenTheInjectedClockReachesIt(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $driver = new DbQueueDriver($this->sqlitePdo(), clock: $clock);
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));
        $reserved = $driver->reserve();
        $this->assertNotNull($reserved);

        $driver->release($reserved, 60);

        $clock->advance(59.0);
        $this->assertNull($driver->reserve(), 'one second before the delay elapses, still not due');

        $clock->advance(1.0);
        $this->assertNotNull($driver->reserve(), 'exactly at the delay, due');
    }

    public function testRejectsAnUnsafeTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $driver = new DbQueueDriver($this->sqlitePdo(), 'jobs; DROP TABLE jobs');
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));
    }

    /**
     * The job id goes through the injected RandomnessInterface seam rather
     * than a direct random_bytes() call, per §6.2 of the record/replay
     * determinism plan -- so a replay engine can reproduce exactly which job
     * id a recorded push produced.
     */
    public function testJobIdIsDerivedFromTheInjectedRandomnessSeam(): void
    {
        $driver = new DbQueueDriver(
            $this->sqlitePdo(),
            randomness: new SeededRandomness(42),
        );
        $driver->push(new JobPayload(DbQueueDriverTestJob::class));

        $reserved = $driver->reserve();

        $this->assertNotNull($reserved);
        $this->assertSame(bin2hex((new SeededRandomness(42))->bytes(16)), $reserved->id);
    }

    public function testSameSeedProducesTheSameJobIdAcrossTwoDrivers(): void
    {
        $driverA = new DbQueueDriver($this->sqlitePdo(), randomness: new SeededRandomness(7));
        $driverB = new DbQueueDriver($this->sqlitePdo(), randomness: new SeededRandomness(7));

        $driverA->push(new JobPayload(DbQueueDriverTestJob::class));
        $driverB->push(new JobPayload(DbQueueDriverTestJob::class));

        $reservedA = $driverA->reserve();
        $reservedB = $driverB->reserve();
        $this->assertNotNull($reservedA);
        $this->assertNotNull($reservedB);

        $this->assertSame($reservedA->id, $reservedB->id);
    }
}
