<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Scheduler\InlineCallbackTask;
use Quiote\Scheduler\ScheduledTaskDefinition;

final class ScheduledTaskDefinitionTest extends TestCase
{
    private function definition(): ScheduledTaskDefinition
    {
        return new ScheduledTaskDefinition(new InlineCallbackTask(static function (): void {}));
    }

    public function testEveryMinuteIsDueAtAnyMinute(): void
    {
        $definition = $this->definition()->everyMinute();

        $this->assertTrue($definition->isDueAt(new \DateTimeImmutable('2026-07-27 10:17:00')));
        $this->assertTrue($definition->isDueAt(new \DateTimeImmutable('2026-07-27 10:18:00')));
    }

    public function testHourlyIsDueOnlyAtTheTopOfTheHour(): void
    {
        $definition = $this->definition()->hourly();

        $this->assertTrue($definition->isDueAt(new \DateTimeImmutable('2026-07-27 10:00:00')));
        $this->assertFalse($definition->isDueAt(new \DateTimeImmutable('2026-07-27 10:30:00')));
    }

    public function testDailyIsDueOnlyAtMidnight(): void
    {
        $definition = $this->definition()->daily();

        $this->assertTrue($definition->isDueAt(new \DateTimeImmutable('2026-07-27 00:00:00')));
        $this->assertFalse($definition->isDueAt(new \DateTimeImmutable('2026-07-27 12:00:00')));
    }

    public function testDailyAtProducesTheCorrectCronExpression(): void
    {
        $definition = $this->definition()->dailyAt('06:30');

        $this->assertTrue($definition->isDueAt(new \DateTimeImmutable('2026-07-27 06:30:00')));
        $this->assertFalse($definition->isDueAt(new \DateTimeImmutable('2026-07-27 06:31:00')));
    }

    public function testCustomCronExpression(): void
    {
        $definition = $this->definition()->cron('*/5 * * * *');

        $this->assertTrue($definition->isDueAt(new \DateTimeImmutable('2026-07-27 10:15:00')));
        $this->assertFalse($definition->isDueAt(new \DateTimeImmutable('2026-07-27 10:16:00')));
    }

    public function testWithoutOverlappingSetsATtl(): void
    {
        $definition = $this->definition();
        $this->assertNull($definition->lockTtlSeconds());

        $definition->withoutOverlapping(120);
        $this->assertSame(120, $definition->lockTtlSeconds());
    }

    public function testLockKeyIsStableAcrossCalls(): void
    {
        $definition = $this->definition()->hourly();

        $this->assertSame($definition->lockKey(), $definition->lockKey());
    }

    public function testInvalidCronExpressionThrows(): void
    {
        $definition = $this->definition()->cron('not a cron expression');

        $this->expectException(\InvalidArgumentException::class);
        $definition->isDueAt(new \DateTimeImmutable());
    }
}
