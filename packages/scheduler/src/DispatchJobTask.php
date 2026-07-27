<?php

namespace Quiote\Scheduler;

use Quiote\DI\Container;
use Quiote\Queue\Job;
use Quiote\Queue\QueueManager;
use RuntimeException;

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

    public function run(Container $container): void
    {
        $manager = $container->get(QueueManager::class);
        if (!$manager instanceof QueueManager) {
            throw new RuntimeException(sprintf(
                'Expected "%s" service to be a QueueManager, got %s.',
                QueueManager::class,
                get_debug_type($manager),
            ));
        }

        $manager->push($this->jobClass, $this->params);
    }

    public function label(): string
    {
        return $this->jobClass;
    }
}
