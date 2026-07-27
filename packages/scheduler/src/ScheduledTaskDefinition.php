<?php

namespace Quiote\Scheduler;

use Cron\CronExpression;

/**
 * Fluent builder for a single scheduled task: its cron spec, the action to
 * run when due, and optional overlap-prevention locking. Returned by
 * {@see Schedule::job()}/{@see Schedule::call()}; an app chains
 * `->hourly()`/`->cron(...)`/`->withoutOverlapping()` onto the result.
 */
final class ScheduledTaskDefinition
{
    private string $cronExpression = '* * * * *';
    private ?string $lockName = null;
    private ?int $lockTtlSeconds = null;

    public function __construct(private readonly ScheduledTaskAction $action)
    {
    }

    public function cron(string $expression): self
    {
        $this->cronExpression = $expression;
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /** @param string $time A "HH:MM" 24-hour time. */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);
        return $this->cron(sprintf('%d %d * * *', $minute, $hour));
    }

    /**
     * Opt into best-effort overlap prevention via {@see SchedulerLock}. PSR-16
     * has no atomic add-if-absent, so there is a narrow race window between
     * concurrent `schedule:run` invocations checking and acquiring the lock —
     * acceptable for the common case (a slow task still running when the next
     * minute's invocation starts), not a hardened distributed lock.
     */
    public function withoutOverlapping(int $ttlSeconds = 3600): self
    {
        $this->lockTtlSeconds = $ttlSeconds;
        return $this;
    }

    public function isDueAt(\DateTimeImmutable $now): bool
    {
        return new CronExpression($this->cronExpression)->isDue($now);
    }

    public function action(): ScheduledTaskAction
    {
        return $this->action;
    }

    public function lockTtlSeconds(): ?int
    {
        return $this->lockTtlSeconds;
    }

    /**
     * Deterministic across separate `schedule:run` process invocations (unlike
     * an object identity hash) so overlap detection actually works between
     * them — derived from the action's label and cron expression, which are
     * stable for a given task definition in code.
     */
    public function lockKey(): string
    {
        return $this->lockName ??= 'scheduler.lock.' . md5($this->action->label() . '|' . $this->cronExpression);
    }

    public function description(): string
    {
        return sprintf('%s (%s)', $this->action->label(), $this->cronExpression);
    }
}
