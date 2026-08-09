<?php

namespace Quiote\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * An {@see Event} whose propagation a listener can halt. Once
 * {@see stopPropagation()} is called, {@see EventDispatcher::dispatch()} stops
 * invoking further listeners — the standard PSR-14 stoppable contract.
 */
abstract class StoppableEvent extends Event implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    /** Halts propagation so the dispatcher invokes no further listeners for this event. */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /** Whether a listener has already called {@see stopPropagation()} on this event. */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
