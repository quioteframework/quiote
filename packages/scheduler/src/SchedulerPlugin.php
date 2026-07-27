<?php

namespace Quiote\Scheduler;

use Quiote\Cache\CacheManager;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Scheduler\Console\ScheduleRunCommand;

/**
 * Registers the scheduler subsystem: a default no-op {@see Schedule} (so an
 * app with nothing configured just runs zero tasks instead of erroring),
 * the {@see SchedulerLock} service, and `schedule:run`. An app overrides
 * {@see Schedule} by binding its own subclass in `Config/factories.xml`.
 */
#[PluginAttribute(name: 'quiote/scheduler')]
final class SchedulerPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->service(Schedule::class, static fn() => new class extends Schedule {
            protected function define(): void
            {
            }
        });

        $registrar->service(SchedulerLock::class, static fn() => new SchedulerLock(CacheManager::getCache()));

        $registrar->command(ScheduleRunCommand::class);
    }
}
