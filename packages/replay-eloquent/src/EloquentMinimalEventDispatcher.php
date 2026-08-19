<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Eloquent;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * A minimal `Illuminate\Contracts\Events\Dispatcher` implementation, used
 * only as the fallback `EloquentQueryRecorder::attach()` installs on a
 * `Illuminate\Database\Connection` that has no event dispatcher of its own --
 * which is the case for `Quiote\Database\Adapter\Eloquent\EloquentDatabase`'s
 * `connect()`, which never calls `Capsule::setEventDispatcher()`. `illuminate/events`
 * is not a dependency of this codebase (or of `illuminate/database` itself),
 * so this exists rather than pulling in the full package for one interface.
 *
 * Supports exactly what `Illuminate\Database\Connection` actually calls on a
 * connection-level event dispatcher: `listen()`/`dispatch()`/`hasListeners()`
 * for query, transaction and connection-lifecycle events, and `until()` as a
 * thin wrapper over `dispatch()`. The queued-event API (`push()`/`flush()`/
 * `forgetPushed()`) and `subscribe()` are not part of that surface and are
 * intentionally unimplemented (no-ops / throwing) -- if an application
 * attaches this to anything beyond a single DB connection, that is a sign it
 * needs a real event dispatcher, not this one.
 */
final class EloquentMinimalEventDispatcher implements Dispatcher
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /**
     * @param \Closure|string|array<int, string> $events
     * @param \Closure|string|array<int, string>|null $listener
     */
    #[\Override]
    public function listen($events, $listener = null): void
    {
        foreach ((array) $events as $event) {
            if (!is_string($event) || $listener === null || !is_callable($listener)) {
                continue;
            }
            $this->listeners[$event][] = $listener;
        }
    }

    #[\Override]
    public function hasListeners($eventName): bool
    {
        return !empty($this->listeners[$eventName] ?? []);
    }

    #[\Override]
    public function subscribe($subscriber): void
    {
        throw new \RuntimeException('EloquentMinimalEventDispatcher does not support subscribe(); attach a real event dispatcher instead.');
    }

    /** @param array<int, mixed> $payload */
    #[\Override]
    public function until($event, $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * @param array<int, mixed> $payload
     * @return mixed
     */
    #[\Override]
    public function dispatch($event, $payload = [], $halt = false): mixed
    {
        $eventName = is_object($event) ? $event::class : (string) $event;
        $arguments = is_object($event) ? [$event] : (array) $payload;

        /** @var list<mixed> $responses */
        $responses = [];
        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            $response = $listener(...$arguments);
            if ($halt && $response !== null) {
                return $response;
            }
            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /** @param array<int, mixed> $payload */
    #[\Override]
    public function push($event, $payload = []): void
    {
        // Queued events are not part of this dispatcher's supported surface.
    }

    #[\Override]
    public function flush($event): void
    {
        // Queued events are not part of this dispatcher's supported surface.
    }

    #[\Override]
    public function forget($event): void
    {
        unset($this->listeners[$event]);
    }

    #[\Override]
    public function forgetPushed(): void
    {
        // Queued events are not part of this dispatcher's supported surface.
    }
}
