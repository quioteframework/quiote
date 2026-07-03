<?php

namespace Quiote\Event\Lifecycle;

use Quiote\Event\StoppableEvent;
use Quiote\Execution\ActionDescriptor;

/**
 * Emitted by {@see \Quiote\Execution\ActionExecutor::execute()} just before an
 * action runs. Stoppable: a listener may call {@see stopPropagation()} to
 * signal intent to short-circuit (reserved for future use — the executor still
 * runs the action today; this event is currently observational).
 */
final class ActionBeforeEvent extends StoppableEvent
{
    public function __construct(
        public readonly ActionDescriptor $descriptor,
    ) {}
}
