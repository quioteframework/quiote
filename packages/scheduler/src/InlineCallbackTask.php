<?php

namespace Quiote\Scheduler;

use Quiote\DI\Container;

/**
 * Runs a callback synchronously in-process, for tasks cheap enough not to
 * need the queue. A callback that throws propagates uncaught — the caller
 * (the `schedule:run` command) is responsible for catching per-task
 * failures, not this class.
 */
final class InlineCallbackTask implements ScheduledTaskAction
{
    public function __construct(private readonly \Closure $callback)
    {
    }

    public function run(Container $container): void
    {
        ($this->callback)($container);
    }

    public function label(): string
    {
        return 'inline callback';
    }
}
