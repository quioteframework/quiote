<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Scheduler\Schedule;
use Quiote\Scheduler\ScheduledTaskDefinition;

final class ScheduleTest extends TestCase
{
    public function testBuildReturnsAllRegisteredDefinitions(): void
    {
        $schedule = new class extends Schedule {
            protected function define(): void
            {
                $this->job(NoopJobForScheduleTest::class)->hourly();
                $this->call(static function (): void {})->daily();
            }
        };

        $definitions = $schedule->build();

        $this->assertCount(2, $definitions);
        $this->assertContainsOnlyInstancesOf(ScheduledTaskDefinition::class, $definitions);
    }

    public function testBuildDoesNotAccumulateDefinitionsAcrossCalls(): void
    {
        $schedule = new class extends Schedule {
            protected function define(): void
            {
                $this->job(NoopJobForScheduleTest::class)->hourly();
            }
        };

        $schedule->build();
        $second = $schedule->build();

        $this->assertCount(1, $second);
    }

    public function testDefaultNoOpScheduleReturnsNoDefinitions(): void
    {
        $schedule = new class extends Schedule {
            protected function define(): void
            {
            }
        };

        $this->assertSame([], $schedule->build());
    }
}

final class NoopJobForScheduleTest implements \Quiote\Queue\Job
{
    public function handle(): void
    {
    }
}
