<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Plugin\PluginManager;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\Job;
use Quiote\Queue\LogFailedJobStore;
use Quiote\Queue\QueuePlugin;
use Quiote\Scheduler\Console\ScheduleRunCommand;
use Quiote\Scheduler\Schedule;
use Quiote\Scheduler\SchedulerLock;
use Quiote\Scheduler\SchedulerPlugin;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unlike `QueueWorkCommandTest`, this forces a real `Quiote::bootstrap()`
 * (see `container()`) rather than only wiring `PluginManager` into an
 * already-built container: `ScheduleRunCommand` resolves its `Schedule`
 * from whichever `Context`/container `core.default_context` names, and that
 * setting isn't settled until bootstrap runs — running this test in
 * isolation (without an earlier test having already bootstrapped Quiote)
 * would otherwise configure a different context than the one the command
 * itself resolves inside `bootstrapApp()`.
 */
final class ScheduleRunCommandTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetPluginState(): void
    {
        PluginManager::reset();
    }

    private function container(): Container
    {
        // Force a full (re-)bootstrap here rather than relying on setUp()'s
        // conditional one, which skips bootstrapping entirely once
        // `core.environment` is already set by tests/bootstrap.php. Without
        // this, `core.default_context` can still read the bootstrap-file
        // default when this test runs in isolation, while
        // `ScheduleRunCommand::execute()`'s own `bootstrapApp()` call
        // triggers the real bootstrap later and settles it to the app's
        // actual default — resolving a different Context/container than
        // the one configured here.
        PluginManager::add(new SchedulerPlugin());
        PluginManager::add(new QueuePlugin());
        \Quiote\Quiote::bootstrap('testing');
        $container = Context::getInstance(Config::getString('core.default_context', 'web'))->getContainer();
        PluginManager::configureContainer($container);

        // `Context::getInstance()` caches its `Container` for the life of the
        // process, and `configureContainer()` only registers a service if it's
        // still absent -- so a `Schedule::class` bound by an earlier test in
        // this class (or in `depends,defects` reordering, an earlier test
        // altogether) would otherwise still be resolved here. Rebind the
        // default no-op schedule unconditionally so every test starts clean;
        // tests that need a specific schedule call `$container->set()` again
        // afterwards, which overrides this.
        $container->set(Schedule::class, new class extends Schedule {
            protected function define(): void
            {
            }
        });
        $container->set(FailedJobStoreInterface::class, new LogFailedJobStore());
        $container->set(SchedulerLock::class, new SchedulerLock(new Psr16Cache(new ArrayAdapter())));

        return $container;
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ScheduleRunCommand());
    }

    public function testRunsAnAlwaysDueTask(): void
    {
        AlwaysDueJobForScheduleRunCommandTest::$handled = false;

        $container = $this->container();
        $container->set(Schedule::class, new class extends Schedule {
            protected function define(): void
            {
                $this->job(AlwaysDueJobForScheduleRunCommandTest::class)->everyMinute();
            }
        });

        $exitCode = $this->tester()->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(AlwaysDueJobForScheduleRunCommandTest::$handled);
    }

    public function testSkipsATaskThatIsNotDue(): void
    {
        $container = $this->container();
        $container->set(Schedule::class, new class extends Schedule {
            protected function define(): void
            {
                // February 30th never occurs, so this is never due.
                $this->call(static function (): void {
                    throw new RuntimeException('must not run');
                })->cron('0 0 30 2 *');
            }
        });

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Ran 0', $tester->getDisplay());
    }

    public function testSkipsALockedOverlappingTask(): void
    {
        $container = $this->container();
        $container->set(Schedule::class, new class extends Schedule {
            protected function define(): void
            {
                $this->job(AlwaysDueJobForScheduleRunCommandTest::class)
                    ->everyMinute()
                    ->withoutOverlapping();
            }
        });

        $lock = $container->get(SchedulerLock::class);
        $this->assertInstanceOf(SchedulerLock::class, $lock);
        $schedule = $container->get(Schedule::class);
        $this->assertInstanceOf(Schedule::class, $schedule);
        $lock->acquire($schedule->build()[0]->lockKey(), 60);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('already running', $tester->getDisplay());
    }

    public function testAFailingTaskIsReportedAndDoesNotBlockTheRun(): void
    {
        AlwaysDueJobForScheduleRunCommandTest::$handled = false;

        $container = $this->container();
        $container->set(Schedule::class, new class extends Schedule {
            protected function define(): void
            {
                $this->call(static function (): void {
                    throw new RuntimeException('task blew up');
                })->everyMinute();
                $this->job(AlwaysDueJobForScheduleRunCommandTest::class)->everyMinute();
            }
        });

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('task blew up', $tester->getDisplay());
        $this->assertTrue(AlwaysDueJobForScheduleRunCommandTest::$handled, 'the second task should still have run');
    }

    public function testWithNoAppScheduleDefinedTheDefaultEmptyScheduleRunsCleanly(): void
    {
        $this->container();

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Ran 0', $tester->getDisplay());
    }
}

final class AlwaysDueJobForScheduleRunCommandTest implements Job
{
    public static bool $handled = false;

    public function handle(): void
    {
        self::$handled = true;
    }
}
