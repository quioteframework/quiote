<?php

namespace Quiote\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A minimal PSR-14 dispatcher over a {@see ListenerProvider}.
 *
 * Per PSR-14, the dispatcher does not swallow listener exceptions — a throwing
 * listener propagates to the caller (fail-loud). Framework emit sites that must
 * survive a misbehaving listener (the request pipeline) wrap their own
 * {@see dispatch()} call in try/catch; see {@see Events} call sites.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly ListenerProvider $provider = new ListenerProvider()) {}

    /** Returns the listener provider this dispatcher draws its listeners from, for registration. */
    public function provider(): ListenerProvider
    {
        return $this->provider;
    }

    /**
     * Passes the event to every matching listener and returns the same instance.
     *
     * Listeners run in the order the provider yields them (priority first, then
     * registration order). If the event implements
     * {@see StoppableEventInterface}, propagation is checked before the first
     * listener — an already-stopped event is returned untouched — and again
     * after each listener, breaking out as soon as one stops it. Listener
     * exceptions are not caught and propagate to the caller.
     */
    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;
        if ($stoppable && $event->isPropagationStopped()) {
            return $event;
        }

        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            $listener($event);
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }
        }

        return $event;
    }
}
