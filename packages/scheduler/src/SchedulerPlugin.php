<?php

namespace Quiote\Scheduler;

use Quiote\Cache\CacheManager;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Scheduler\Console\ScheduleRunCommand;
use Quiote\DI\Container;

/**
 * Registers the scheduler subsystem: a default no-op {@see Schedule} (so an
 * app with nothing configured just runs zero tasks instead of erroring),
 * the {@see SchedulerLock} service, and `schedule:run`. An app overrides
 * {@see Schedule} by binding its own subclass in `Config/factories.xml`.
 */
#[PluginAttribute(name: 'quiote/scheduler')]
final class SchedulerPlugin implements PluginInterface
{
    /**
     * Registers the scheduler's services and console command.
     *
     * Binds {@see Schedule} as a singleton to an anonymous subclass that
     * defines no tasks, so an app that has not bound its own schedule runs
     * nothing instead of failing; binds {@see SchedulerLock} as a singleton
     * over the configured cache; and registers `schedule:run`.
     */
    public function register(PluginRegistrar $registrar): void
    {
        // The schedule holds the tasks an application registers against it, so it has to be the same
        // object for the process rather than a fresh empty one per request.
        $registrar->service(Schedule::class, static fn() => new class extends Schedule {
            protected function define(): void
            {
            }
        }, Container::SCOPE_SINGLETON);

        $registrar->service(
            SchedulerLock::class,
            static fn() => new SchedulerLock(CacheManager::getCache()),
            Container::SCOPE_SINGLETON,
        );

        $registrar->command(ScheduleRunCommand::class);
    }
}
