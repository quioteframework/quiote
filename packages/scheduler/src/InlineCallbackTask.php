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

    /**
     * Invokes the wrapped callback synchronously, passing it the container.
     *
     * Anything the callback throws propagates to the caller; this class does
     * no error handling of its own.
     */
    public function run(Container $container): void
    {
        ($this->callback)($container);
    }

    /**
     * Returns a fixed label for the task.
     *
     * A closure has no meaningful name, so every inline task reports
     * `inline callback`.
     */
    public function label(): string
    {
        return 'inline callback';
    }
}
