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

    public function provider(): ListenerProvider
    {
        return $this->provider;
    }

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
