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
    public function run(Container $container): void;

    public function label(): string;
}
