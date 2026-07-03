<?php

namespace Quiote\Event;

use Quiote\Logging\Level;
use Quiote\Logging\Log;

/**
 * Static facade for the event subsystem, mirroring {@see \Quiote\Logging\Log}
 * and {@see \Quiote\Telemetry\Trace}: a process-global, worker-lifetime
 * listener registry configured once (typically by plugins at boot) and used
 * everywhere via the facade, with no per-request wiring.
 *
 *   use Quiote\Event\Events;
 *   Events::listen(RequestMatchedEvent::class, fn($e) => ...);
 *   Events::dispatch(new RequestMatchedEvent(...));
 *
 * Emit sites in the request pipeline should gate on {@see hasListeners()} so a
 * no-listener app never even allocates the event object, and should use
 * {@see emit()} (which try/catches) rather than {@see dispatch()} directly so a
 * buggy listener can't take down a request.
 */
final class Events
{
    private static ?EventDispatcher $dispatcher = null;

    private function __construct() {}

    public static function dispatcher(): EventDispatcher
    {
        return self::$dispatcher ??= new EventDispatcher();
    }

    public static function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        self::dispatcher()->provider()->listen($eventClass, $listener, $priority);
    }

    public static function hasListeners(string $eventClass): bool
    {
        return self::dispatcher()->provider()->hasListenersFor($eventClass);
    }

    /** Dispatch an event, returning it (PSR-14). Listener exceptions propagate. */
    public static function dispatch(object $event): object
    {
        return self::dispatcher()->dispatch($event);
    }

    /**
     * Safe dispatch for pipeline/lifecycle emit sites: dispatches only if a
     * listener exists, and never lets a listener exception escape into the
     * request/bootstrap path (logs it instead, same "never crash the request"
     * posture telemetry holds). Returns the event (or the un-dispatched event
     * if there were no listeners).
     */
    public static function emit(object $event): object
    {
        if (!self::hasListeners($event::class)) {
            return $event;
        }
        try {
            return self::dispatch($event);
        } catch (\Throwable $e) {
            Log::for(self::class)->error(
                '[Events] listener for ' . $event::class . ' threw: ' . $e::class . ': ' . $e->getMessage()
            );
            return $event;
        }
    }

    public static function reset(): void
    {
        self::$dispatcher?->provider()->reset();
        self::$dispatcher = null;
    }
}
