<?php

namespace Quiote\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Priority-ordered PSR-14 listener provider.
 *
 * Listeners are registered against an event class name and are also matched
 * for any subclass, implemented interface, or parent class of the dispatched
 * event — so a listener on a base event (or an interface) sees every concrete
 * subtype, the usual "listen broadly, dispatch specifically" behavior. Within
 * a single event type, higher priority runs first; ties preserve registration
 * order (stable).
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<string, list<array{listener: callable, priority: int, seq: int}>> */
    private array $listeners = [];

    private int $seq = 0;

    /** @var array<string, list<callable>> memoized resolved listener lists per concrete event class */
    private array $resolved = [];

    public function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = ['listener' => $listener, 'priority' => $priority, 'seq' => $this->seq++];
        $this->resolved = [];
    }

    /**
     * Whether any registered listener would fire for this event class (matching
     * the same subclass/interface/parent rules {@see getListenersForEvent()}
     * uses). Cheap gate for hot-path emit sites so a no-listener app pays only
     * this lookup, not an event-object allocation.
     */
    public function hasListenersFor(string $eventClass): bool
    {
        if ($this->listeners === []) {
            return false;
        }
        foreach ($this->typeChain($eventClass) as $type) {
            if (!empty($this->listeners[$type])) {
                return true;
            }
        }
        return false;
    }

    /** @return iterable<callable> */
    public function getListenersForEvent(object $event): iterable
    {
        return $this->resolved[$event::class] ??= $this->resolveFor($event::class);
    }

    public function reset(): void
    {
        $this->listeners = [];
        $this->resolved = [];
        $this->seq = 0;
    }

    /** @return list<callable> */
    private function resolveFor(string $eventClass): array
    {
        $matched = [];
        foreach ($this->typeChain($eventClass) as $type) {
            foreach ($this->listeners[$type] ?? [] as $entry) {
                $matched[] = $entry;
            }
        }
        // Higher priority first; stable on registration sequence within equal priority.
        usort($matched, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority'] ?: $a['seq'] <=> $b['seq']);

        return array_map(static fn(array $e): callable => $e['listener'], $matched);
    }

    /** @return list<string> the event class plus every parent class and implemented interface */
    private function typeChain(string $eventClass): array
    {
        $types = [$eventClass];
        // class_parents/class_implements accept an existing class name; guard for safety.
        if (class_exists($eventClass) || interface_exists($eventClass)) {
            $types = [
                ...$types,
                ...array_values(class_parents($eventClass) ?: []),
                ...array_values(class_implements($eventClass) ?: []),
            ];
        }
        return $types;
    }
}
