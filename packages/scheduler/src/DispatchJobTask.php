<?php

namespace Quiote\Scheduler;

use Quiote\DI\Container;
use Quiote\Queue\Job;
use Quiote\Queue\QueueManager;

/**
 * Pushes a {@see Job} onto {@see QueueManager} rather than running it
 * in-process — honors whatever queue driver the app has configured (sync
 * or persistent).
 */
final readonly class DispatchJobTask implements ScheduledTaskAction
{
    /**
     * @param class-string<Job> $jobClass
     * @param array<string, mixed> $params
     */
    public function __construct(
        private string $jobClass,
        private array $params = [],
    ) {
    }

    /**
     * Pushes the configured job class and parameters onto the queue.
     *
     * Resolves {@see QueueManager} from the container and enqueues the job
     * rather than executing it here, so the app's queue driver decides whether
     * it runs now or later.
     */
    public function run(Container $container): void
    {
        $container->get(QueueManager::class)->push($this->jobClass, $this->params);
    }

    /** Returns the job's class name as the task's label. */
    public function label(): string
    {
        return $this->jobClass;
    }
}
