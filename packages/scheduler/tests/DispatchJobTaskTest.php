<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\Job;
use Quiote\Queue\JobExecutor;
use Quiote\Queue\LogFailedJobStore;
use Quiote\Queue\QueueConfig;
use Quiote\Queue\QueueManager;
use Quiote\Scheduler\DispatchJobTask;

final class DispatchJobTaskTest extends TestCase
{
    private function queueBackedContainer(): Container
    {
        $container = new Container();
        $container->set(FailedJobStoreInterface::class, new LogFailedJobStore());
        $container->set(JobExecutor::class, new JobExecutor($container, new LogFailedJobStore(), 3, 5));
        $container->set(QueueManager::class, new QueueManager($container, new QueueConfig('sync', 3, 5)));

        return $container;
    }

    public function testRunPushesTheJobOntoTheQueueManagerAndItRunsSynchronously(): void
    {
        RecordingJobForDispatchTaskTest::$ranWith = null;

        $task = new DispatchJobTask(RecordingJobForDispatchTaskTest::class, ['who' => 'scheduler']);
        $task->run($this->queueBackedContainer());

        $this->assertSame(['who' => 'scheduler'], RecordingJobForDispatchTaskTest::$ranWith);
    }

    public function testLabelIsTheJobClassName(): void
    {
        $task = new DispatchJobTask(RecordingJobForDispatchTaskTest::class);

        $this->assertSame(RecordingJobForDispatchTaskTest::class, $task->label());
    }

    public function testRunThrowsWhenQueueManagerIsNotBound(): void
    {
        $task = new DispatchJobTask(RecordingJobForDispatchTaskTest::class);

        $this->expectException(RuntimeException::class);
        $task->run(new Container());
    }
}

final class RecordingJobForDispatchTaskTest implements Job
{
    /** @var array<string, mixed>|null */
    public static ?array $ranWith = null;

    public function __construct(private readonly string $who = 'unset')
    {
    }

    public function handle(): void
    {
        self::$ranWith = ['who' => $this->who];
    }
}
