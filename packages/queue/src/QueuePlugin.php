<?php

namespace Quiote\Queue;

use Quiote\DI\Container;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Queue\Console\QueueFailedForgetCommand;
use Quiote\Queue\Console\QueueFailedListCommand;
use Quiote\Queue\Console\QueueFailedRetryCommand;
use Quiote\Queue\Console\QueueWorkCommand;

/**
 * Registers the queue subsystem: `queue.*` setting defaults (`sync` driver,
 * out of the box), a default {@see LogFailedJobStore}, the
 * {@see QueueManager}/{@see QueueWorker} services, `queue:work`, and the
 * `queue:failed:*` dead-letter inspection commands (a no-op error, not a
 * crash, against the default store — see {@see
 * \Quiote\Queue\Console\AbstractQueueFailedCommand::resolveInspectableStore()}).
 * A persistent backend (e.g. `quioteframework/queue-db`) registers its own
 * alias into {@see QueueDriverRegistry} from its own plugin — this class
 * does not need to change for that.
 */
#[PluginAttribute(name: 'quiote/queue')]
final class QueuePlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('queue.default_driver', 'sync');
        $registrar->configDefault('queue.retry.max_attempts', 3);
        $registrar->configDefault('queue.retry.backoff_seconds', 5);

        $registrar->service(
            FailedJobStoreInterface::class,
            static fn() => new LogFailedJobStore(),
            Container::SCOPE_SINGLETON,
        );

        $registrar->service(QueueConfig::class, static fn() => QueueConfig::fromConfig(), Container::SCOPE_SINGLETON);

        $registrar->service(
            JobExecutor::class,
            static fn(Container $container) => new JobExecutor(
                $container,
                self::resolveFailedJobStore($container),
                self::resolveQueueConfig($container)->retryMaxAttempts,
                self::resolveQueueConfig($container)->retryBackoffSeconds,
            ),
            Container::SCOPE_SINGLETON,
        );

        $registrar->service(
            QueueWorker::class,
            static fn(Container $container) => new QueueWorker(self::resolveJobExecutor($container)),
            Container::SCOPE_SINGLETON,
        );

        $registrar->service(
            QueueManager::class,
            static fn(Container $container) => new QueueManager($container, self::resolveQueueConfig($container)),
            Container::SCOPE_SINGLETON,
        );

        $registrar->command(QueueWorkCommand::class);
        $registrar->command(QueueFailedListCommand::class);
        $registrar->command(QueueFailedRetryCommand::class);
        $registrar->command(QueueFailedForgetCommand::class);
    }

    private static function resolveFailedJobStore(Container $container): FailedJobStoreInterface
    {
        // No type guard here or below: the container refuses to answer a class or interface name with
        // anything that is not an instance of it, so a wrong binding throws there naming the id.
        return $container->get(FailedJobStoreInterface::class);
    }

    private static function resolveQueueConfig(Container $container): QueueConfig
    {
        return $container->get(QueueConfig::class);
    }

    private static function resolveJobExecutor(Container $container): JobExecutor
    {
        return $container->get(JobExecutor::class);
    }
}
