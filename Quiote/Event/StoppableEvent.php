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

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
