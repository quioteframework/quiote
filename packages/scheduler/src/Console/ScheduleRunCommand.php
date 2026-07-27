<?php

namespace Quiote\Scheduler\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Scheduler\Schedule;
use Quiote\Scheduler\SchedulerLock;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Evaluates every task in the app's {@see Schedule} against "now" and runs
 * the due ones once, then exits — this is meant to be invoked by the OS's
 * own crontab (`* * * * * php bin/quiote schedule:run`) every minute, like
 * every cron-based scheduler, not a long-running daemon loop. A task
 * throwing is caught and reported but does not abort the run — one bad
 * task must not block the rest.
 */
#[AsCommand(name: 'schedule:run', description: 'Run scheduled tasks that are due right now')]
final class ScheduleRunCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $context = Context::getInstance(Config::getString('core.default_context', 'web'));
        $container = $context->getContainer();

        $schedule = $container->get(Schedule::class);
        if (!$schedule instanceof Schedule) {
            $io->error(sprintf('Expected "%s" service to be a Schedule, got %s.', Schedule::class, get_debug_type($schedule)));
            return self::FAILURE;
        }

        $now = new \DateTimeImmutable();
        $ran = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($schedule->build() as $definition) {
            if (!$definition->isDueAt($now)) {
                continue;
            }

            $lock = null;
            if ($definition->lockTtlSeconds() !== null) {
                $lock = $this->resolveLock($container);
                if (!$lock->acquire($definition->lockKey(), $definition->lockTtlSeconds())) {
                    $skipped++;
                    $io->writeln(sprintf('Skipped (already running): %s', $definition->description()));
                    continue;
                }
            }

            try {
                $definition->action()->run($container);
                $ran++;
            } catch (\Throwable $e) {
                $failed++;
                $io->error(sprintf('Task failed: %s — %s', $definition->description(), $e->getMessage()));
            } finally {
                $lock?->release($definition->lockKey());
            }
        }

        $io->success(sprintf('Ran %d, skipped %d (locked), failed %d.', $ran, $skipped, $failed));
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveLock(Container $container): SchedulerLock
    {
        $lock = $container->get(SchedulerLock::class);
        if (!$lock instanceof SchedulerLock) {
            throw new RuntimeException(sprintf('Expected "%s" service to be a SchedulerLock, got %s.', SchedulerLock::class, get_debug_type($lock)));
        }
        return $lock;
    }
}
