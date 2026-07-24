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
use RuntimeException;

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

        $registrar->service(FailedJobStoreInterface::class, static fn() => new LogFailedJobStore());

        $registrar->service(QueueConfig::class, static fn() => QueueConfig::fromConfig());

        $registrar->service(
            JobExecutor::class,
            static fn(Container $container) => new JobExecutor(
                $container,
                self::resolveFailedJobStore($container),
                self::resolveQueueConfig($container)->retryMaxAttempts,
                self::resolveQueueConfig($container)->retryBackoffSeconds,
            ),
        );

        $registrar->service(
            QueueWorker::class,
            static fn(Container $container) => new QueueWorker(self::resolveJobExecutor($container)),
        );

        $registrar->service(
            QueueManager::class,
            static fn(Container $container) => new QueueManager($container, self::resolveQueueConfig($container)),
        );

        $registrar->command(QueueWorkCommand::class);
        $registrar->command(QueueFailedListCommand::class);
        $registrar->command(QueueFailedRetryCommand::class);
        $registrar->command(QueueFailedForgetCommand::class);
    }

    private static function resolveFailedJobStore(Container $container): FailedJobStoreInterface
    {
        $store = $container->get(FailedJobStoreInterface::class);
        if (!$store instanceof FailedJobStoreInterface) {
            throw new RuntimeException(sprintf('Expected "%s" service to be a FailedJobStoreInterface, got %s.', FailedJobStoreInterface::class, get_debug_type($store)));
        }
        return $store;
    }

    private static function resolveQueueConfig(Container $container): QueueConfig
    {
        $config = $container->get(QueueConfig::class);
        if (!$config instanceof QueueConfig) {
            throw new RuntimeException(sprintf('Expected "%s" service to be a QueueConfig, got %s.', QueueConfig::class, get_debug_type($config)));
        }
        return $config;
    }

    private static function resolveJobExecutor(Container $container): JobExecutor
    {
        $executor = $container->get(JobExecutor::class);
        if (!$executor instanceof JobExecutor) {
            throw new RuntimeException(sprintf('Expected "%s" service to be a JobExecutor, got %s.', JobExecutor::class, get_debug_type($executor)));
        }
        return $executor;
    }
}
