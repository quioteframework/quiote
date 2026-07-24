<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Quiote\Queue\FailedJob;
use Quiote\Queue\Job;
use Quiote\Queue\LogFailedJobStore;

final class LogFailedJobStoreTestJob implements Job
{
    public function handle(): void
    {
    }
}

final class LogFailedJobStoreSpyLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : get_debug_type($level), (string) $message, $context];
    }
}

final class LogFailedJobStoreTest extends TestCase
{
    public function testRecordLogsAnErrorWithFailureDetails(): void
    {
        $logger = new LogFailedJobStoreSpyLogger();
        $store = new LogFailedJobStore($logger);

        $store->record(new FailedJob(
            jobClass: LogFailedJobStoreTestJob::class,
            params: ['userId' => 5],
            exceptionClass: RuntimeException::class,
            exceptionMessage: 'boom',
            exceptionTrace: '#0 ...',
            attempts: 3,
        ));

        $this->assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        $this->assertSame('error', $level);
        $this->assertStringContainsString(LogFailedJobStoreTestJob::class, $message);
        $this->assertSame(3, $context['attempts']);
        $this->assertSame(['userId' => 5], $context['params']);
    }

    public function testRecordDoesNotThrowWithDefaultNullLogger(): void
    {
        $store = new LogFailedJobStore();

        $store->record(new FailedJob(LogFailedJobStoreTestJob::class, [], RuntimeException::class, 'boom', '', 1));

        $this->addToAssertionCount(1);
    }
}
