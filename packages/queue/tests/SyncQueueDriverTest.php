<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Queue\FailedJob;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\Job;
use Quiote\Queue\JobExecutor;
use Quiote\Queue\JobPayload;
use Quiote\Queue\SyncQueueDriver;

final class SyncQueueDriverSuccessJob implements Job
{
    /** @var list<array<string, mixed>> */
    public static array $handled = [];

    public function __construct(private int $id = 0)
    {
    }

    public function handle(): void
    {
        self::$handled[] = ['id' => $this->id];
    }
}

final class SyncQueueDriverAlwaysFailingJob implements Job
{
    public static int $attempts = 0;

    public function handle(): void
    {
        self::$attempts++;
        throw new RuntimeException('boom');
    }
}

final class SyncQueueDriverSpyFailedJobStore implements FailedJobStoreInterface
{
    /** @var list<FailedJob> */
    public array $recorded = [];

    public function record(FailedJob $failedJob): void
    {
        $this->recorded[] = $failedJob;
    }
}

final class SyncQueueDriverTest extends TestCase
{
    protected function setUp(): void
    {
        SyncQueueDriverSuccessJob::$handled = [];
        SyncQueueDriverAlwaysFailingJob::$attempts = 0;
    }

    public function testPushExecutesJobImmediatelyInProcess(): void
    {
        $driver = new SyncQueueDriver(new JobExecutor(new Container(), new SyncQueueDriverSpyFailedJobStore()));

        $driver->push(new JobPayload(SyncQueueDriverSuccessJob::class, ['id' => 42]));

        $this->assertSame([['id' => 42]], SyncQueueDriverSuccessJob::$handled);
    }

    public function testPushRetriesThenRecordsToFailedJobStoreOnExhaustion(): void
    {
        $store = new SyncQueueDriverSpyFailedJobStore();
        $driver = new SyncQueueDriver(new JobExecutor(new Container(), $store, defaultMaxAttempts: 2, defaultBackoffSeconds: 0));

        $driver->push(new JobPayload(SyncQueueDriverAlwaysFailingJob::class));

        $this->assertSame(2, SyncQueueDriverAlwaysFailingJob::$attempts);
        $this->assertCount(1, $store->recorded);
        $this->assertSame(SyncQueueDriverAlwaysFailingJob::class, $store->recorded[0]->jobClass);
    }
}
