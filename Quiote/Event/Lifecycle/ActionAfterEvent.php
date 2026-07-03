<?php

namespace Quiote\Event\Lifecycle;

use Quiote\Event\Event;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ActionExecutionContext;

/**
 * Emitted by {@see \Quiote\Execution\ActionExecutor::execute()} after an action
 * (and its view) have run, carrying the resulting execution context. Not emitted
 * when the action throws (the exception propagates instead).
 */
final class ActionAfterEvent extends Event
{
    public function __construct(
        public readonly ActionDescriptor $descriptor,
        public readonly ActionExecutionContext $result,
    ) {}
}
