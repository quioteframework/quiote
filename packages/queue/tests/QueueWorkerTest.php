<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Queue\FailedJob;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\Job;
use Quiote\Queue\JobExecutor;
use Quiote\Queue\JobPayload;
use Quiote\Queue\PollableQueueDriverInterface;
use Quiote\Queue\QueueWorker;
use Quiote\Queue\ReservedJob;

final class QueueWorkerSuccessJob implements Job
{
    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}

final class QueueWorkerFailingJob implements Job
{
    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

final class QueueWorkerSpyFailedJobStore implements FailedJobStoreInterface
{
    /** @var list<FailedJob> */
    public array $recorded = [];

    public function record(FailedJob $failedJob): void
    {
        $this->recorded[] = $failedJob;
    }
}

final class QueueWorkerFakeDriver implements PollableQueueDriverInterface
{
    /** @var list<JobPayload> */
    private array $backlog = [];

    /** @var list<ReservedJob> */
    public array $acked = [];
    /** @var list<array{ReservedJob, int}> */
    public array $released = [];
    /** @var list<ReservedJob> */
    public array $discarded = [];

    public function push(JobPayload $payload): void
    {
        $this->backlog[] = $payload;
    }

    public function reserve(): ?ReservedJob
    {
        $payload = array_shift($this->backlog);
        if ($payload === null) {
            return null;
        }
        return new ReservedJob('1', $payload);
    }

    public function ack(ReservedJob $job): void
    {
        $this->acked[] = $job;
    }

    public function release(ReservedJob $job, int $delaySeconds): void
    {
        $this->released[] = [$job, $delaySeconds];
    }

    public function discard(ReservedJob $job): void
    {
        $this->discarded[] = $job;
    }
}

final class QueueWorkerTest extends TestCase
{
    protected function setUp(): void
    {
        QueueWorkerSuccessJob::$handled = 0;
    }

    public function testProcessNextReturnsFalseWhenQueueIsEmpty(): void
    {
        $worker = new QueueWorker(new JobExecutor(new Container(), new QueueWorkerSpyFailedJobStore()));

        $this->assertFalse($worker->processNext(new QueueWorkerFakeDriver()));
    }

    public function testProcessNextAcksOnSuccess(): void
    {
        $driver = new QueueWorkerFakeDriver();
        $driver->push(new JobPayload(QueueWorkerSuccessJob::class));
        $worker = new QueueWorker(new JobExecutor(new Container(), new QueueWorkerSpyFailedJobStore()));

        $this->assertTrue($worker->processNext($driver));
        $this->assertSame(1, QueueWorkerSuccessJob::$handled);
        $this->assertCount(1, $driver->acked);
        $this->assertCount(0, $driver->released);
        $this->assertCount(0, $driver->discarded);
    }

    public function testProcessNextReleasesOnRetryableFailure(): void
    {
        $driver = new QueueWorkerFakeDriver();
        $driver->push(new JobPayload(QueueWorkerFailingJob::class));
        $worker = new QueueWorker(new JobExecutor(new Container(), new QueueWorkerSpyFailedJobStore(), defaultMaxAttempts: 3, defaultBackoffSeconds: 7));

        $this->assertTrue($worker->processNext($driver));
        $this->assertCount(1, $driver->released);
        $this->assertSame(7, $driver->released[0][1]);
        $this->assertCount(0, $driver->acked);
        $this->assertCount(0, $driver->discarded);
    }

    public function testProcessNextDiscardsAfterRetriesExhausted(): void
    {
        $driver = new QueueWorkerFakeDriver();
        $driver->push(new JobPayload(QueueWorkerFailingJob::class));
        $store = new QueueWorkerSpyFailedJobStore();
        $worker = new QueueWorker(new JobExecutor(new Container(), $store, defaultMaxAttempts: 1));

        $this->assertTrue($worker->processNext($driver));
        $this->assertCount(1, $driver->discarded);
        $this->assertCount(1, $store->recorded);
        $this->assertCount(0, $driver->released);
    }
}
