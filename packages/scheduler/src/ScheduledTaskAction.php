<?php

namespace Quiote\Scheduler;

use Quiote\DI\Container;

/**
 * The "how to invoke it" strategy for a {@see ScheduledTaskDefinition} —
 * either run inline ({@see InlineCallbackTask}) or dispatch onto the queue
 * ({@see DispatchJobTask}). The container is always passed explicitly
 * rather than reached for statically, so every implementation stays
 * constructor-injectable and testable.
 */
interface ScheduledTaskAction
{
    /**
     * Performs the task, using the given container to reach any services it
     * needs.
     *
     * Implementations may either do the work in-process or hand it off; they
     * are not expected to catch their own failures, as the caller running the
     * schedule reports and isolates per-task errors.
     */
    public function run(Container $container): void;

    /**
     * Returns a short, stable identifier for the action, used in schedule
     * listings and log output.
     *
     * It must not vary between processes for the same task: it is part of the
     * overlap lock key derived by {@see ScheduledTaskDefinition::lockKey()}.
     */
    public function label(): string;
}
