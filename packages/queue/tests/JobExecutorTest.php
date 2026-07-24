<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Queue\FailedJob;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\Job;
use Quiote\Queue\JobExecutor;
use Quiote\Queue\JobPayload;
use Quiote\Queue\RetryableJob;

final class JobExecutorRecordingSuccessJob implements Job
{
    /** @var list<array<string, mixed>> */
    public static array $handled = [];

    public function __construct(private int $x = 0)
    {
    }

    public function handle(): void
    {
        self::$handled[] = ['x' => $this->x];
    }
}

final class JobExecutorAlwaysFailingJob implements Job
{
    public static int $attempts = 0;

    public function handle(): void
    {
        self::$attempts++;
        throw new RuntimeException('boom');
    }
}

final class JobExecutorRetryableFailingJob implements RetryableJob
{
    public static int $attempts = 0;

    public function handle(): void
    {
        self::$attempts++;
        throw new RuntimeException('retryable boom');
    }

    public function maxAttempts(): int
    {
        return 2;
    }

    public function backoffSeconds(int $attempt): int
    {
        return 0;
    }
}

final class JobExecutorSucceedsOnSecondAttemptJob implements Job
{
    public static int $attempts = 0;

    public function handle(): void
    {
        self::$attempts++;
        if (self::$attempts < 2) {
            throw new RuntimeException('not yet');
        }
    }
}

final class JobExecutorNotAJob
{
}

final class JobExecutorSpyFailedJobStore implements FailedJobStoreInterface
{
    /** @var list<FailedJob> */
    public array $recorded = [];

    public function record(FailedJob $failedJob): void
    {
        $this->recorded[] = $failedJob;
    }
}

final class JobExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        JobExecutorRecordingSuccessJob::$handled = [];
        JobExecutorAlwaysFailingJob::$attempts = 0;
        JobExecutorRetryableFailingJob::$attempts = 0;
        JobExecutorSucceedsOnSecondAttemptJob::$attempts = 0;
    }

    public function testAttemptRunsJobSuccessfully(): void
    {
        $executor = new JobExecutor(new Container(), new JobExecutorSpyFailedJobStore());

        $failure = $executor->attempt(new JobPayload(JobExecutorRecordingSuccessJob::class, ['x' => 1]));

        $this->assertNull($failure);
        $this->assertSame([['x' => 1]], JobExecutorRecordingSuccessJob::$handled);
    }

    public function testAttemptRejectsClassNotImplementingJob(): void
    {
        $executor = new JobExecutor(new Container(), new JobExecutorSpyFailedJobStore());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        $executor->attempt(new JobPayload(JobExecutorNotAJob::class));
    }

    public function testAttemptRetriesUsingJobsOwnPolicyThenRecordsFailure(): void
    {
        $store = new JobExecutorSpyFailedJobStore();
        $executor = new JobExecutor(new Container(), $store);

        $first = $executor->attempt(new JobPayload(JobExecutorRetryableFailingJob::class));
        $this->assertNotNull($first);
        $this->assertTrue($first->shouldRetry);
        $this->assertSame(1, $first->attempts);

        $second = $executor->attempt(new JobPayload(JobExecutorRetryableFailingJob::class, [], $first->attempts));
        $this->assertNotNull($second);
        $this->assertFalse($second->shouldRetry);
        $this->assertSame(2, $second->attempts);

        $this->assertCount(1, $store->recorded);
        $this->assertSame(JobExecutorRetryableFailingJob::class, $store->recorded[0]->jobClass);
        $this->assertSame(2, $store->recorded[0]->attempts);
    }

    public function testAttemptUsesConfiguredDefaultsWhenJobIsNotRetryable(): void
    {
        $store = new JobExecutorSpyFailedJobStore();
        $executor = new JobExecutor(new Container(), $store, defaultMaxAttempts: 1);

        $failure = $executor->attempt(new JobPayload(JobExecutorAlwaysFailingJob::class));

        $this->assertNotNull($failure);
        $this->assertFalse($failure->shouldRetry);
        $this->assertCount(1, $store->recorded);
    }

    public function testExecuteWithRetriesBlocksUntilSuccess(): void
    {
        $executor = new JobExecutor(new Container(), new JobExecutorSpyFailedJobStore(), defaultMaxAttempts: 3, defaultBackoffSeconds: 0);

        $executor->executeWithRetries(new JobPayload(JobExecutorSucceedsOnSecondAttemptJob::class));

        $this->assertSame(2, JobExecutorSucceedsOnSecondAttemptJob::$attempts);
    }

    public function testExecuteWithRetriesGivesUpAfterMaxAttempts(): void
    {
        $store = new JobExecutorSpyFailedJobStore();
        $executor = new JobExecutor(new Container(), $store, defaultMaxAttempts: 2, defaultBackoffSeconds: 0);

        $executor->executeWithRetries(new JobPayload(JobExecutorAlwaysFailingJob::class));

        $this->assertSame(2, JobExecutorAlwaysFailingJob::$attempts);
        $this->assertCount(1, $store->recorded);
    }
}
