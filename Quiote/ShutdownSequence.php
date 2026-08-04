<?php

namespace Quiote;

use Quiote\Logging\Log;
use Quiote\Logging\Level;

/**
 * The ordered list of components to shut down, and the operations on that order.
 *
 * Order is the whole point: a component's shutdown may write through another one, so the sequence
 * is built back-to-front from the factory configuration and every operation here preserves
 * position rather than recomputing it. That is why replacing a component is a splice at its old
 * index and not an append.
 *
 * @since      4.0.0
 */
final class ShutdownSequence
{
    /**
     * @var        array<int, object> Components in shutdown order.
     */
    private array $components = [];

    /**
     * Install the sequence wholesale.
     *
     * The entry point the generated factory cache writes through, which is why it accepts a raw
     * list and filters it: the generated code lists every configured component slot, and an
     * optional slot that was not configured is null there.
     *
     * @param      array<int, mixed> $components
     * @since      4.0.0
     */
    public function replaceAll(array $components): void
    {
        $this->components = array_values(array_filter(
            $components,
            static fn(mixed $component): bool => is_object($component),
        ));
    }

    /**
     * Add a component at the end of the sequence, so it shuts down last.
     *
     * Idempotent by identity: a component already in the sequence keeps its existing position
     * rather than being shut down twice.
     *
     * @since      4.0.0
     */
    public function append(object $component): void
    {
        if (!in_array($component, $this->components, true)) {
            $this->components[] = $component;
        }
    }

    /**
     * Whether the sequence holds this exact component.
     *
     * @since      4.0.0
     */
    public function has(object $component): bool
    {
        return in_array($component, $this->components, true);
    }

    /**
     * The components, in shutdown order.
     *
     * @return     array<int, object>
     * @since      4.0.0
     */
    public function all(): array
    {
        return $this->components;
    }

    /**
     * How many components are in the sequence.
     *
     * @since      4.0.0
     */
    public function count(): int
    {
        return count($this->components);
    }

    /**
     * Drop every component the predicate matches, closing the gaps.
     *
     * @param      callable(object):bool $matches
     * @since      4.0.0
     */
    public function remove(callable $matches): void
    {
        $this->components = array_values(array_filter(
            $this->components,
            static fn(object $component): bool => !$matches($component),
        ));
    }

    /**
     * Replace every component of one role with $replacement, at the role's original position.
     *
     * The lazy worker-mode recreation path needs this: the request boundary nulls a context's
     * property but leaves the dead object here, and a component that is never spliced back in
     * silently stops being shut down from the second request onward.
     *
     * Position is preserved rather than unshifting to the front, which would move the component
     * ahead of the others and skip late mutations they perform. $fallbackIndex is where it goes
     * when the sequence held no instance of the role at all.
     *
     * @param      object $replacement The freshly created component.
     * @param      callable(object):bool $matches Identifies instances of the role.
     * @param      int $fallbackIndex Insertion point when the role was absent.
     * @param      string $caller Label for the debug line, naming why the replacement happened.
     * @since      4.0.0
     */
    public function replaceRole(
        object $replacement,
        callable $matches,
        int $fallbackIndex = 0,
        string $caller = 'replaceRole',
    ): void {
        try {
            $firstIndex = null;
            $removedAny = false;
            foreach ($this->components as $idx => $component) {
                if ($matches($component)) {
                    $firstIndex ??= $idx;
                    unset($this->components[$idx]);
                    $removedAny = true;
                }
            }
            $this->components = array_values($this->components);

            $firstIndex ??= max(0, $fallbackIndex);
            $firstIndex = min($firstIndex, count($this->components));

            array_splice($this->components, $firstIndex, 0, [$replacement]);

            $logger = Log::for($this);
            if ($logger->isEnabled(Level::Debug)) {
                $logger->debug(sprintf(
                    '[ShutdownSequence.%s] component registered replaced=%d idx=%d oid=%d',
                    $caller,
                    $removedAny ? 1 : 0,
                    $firstIndex,
                    spl_object_id($replacement),
                ));
            }
        } catch (\Throwable $e) {
            // The component's own persistence is driven directly by the context's reset/shutdown,
            // so what degrades here is only the order other components are shut down relative to
            // it.
            Log::for($this)->warning(
                '[ShutdownSequence.' . $caller . '] could not splice the component in; shutdown '
                . 'ordering for other components may be wrong: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Shut every component down in order.
     *
     * Each is guarded individually: a shutdown error must not mask whatever was being executed
     * when shutdown began, and must not stop the components after it from shutting down.
     *
     * @param      ?object $skip A component shut down by its own owner -- the user, which the
     *             request-state flush persists so the ordering against the session holds.
     *             Shutting it down again here would double-write.
     * @since      4.0.0
     */
    public function shutdownAll(?object $skip = null): void
    {
        foreach ($this->components as $component) {
            if ($skip !== null && $component === $skip) {
                continue;
            }
            try {
                if (method_exists($component, 'shutdown')) {
                    $component->shutdown();
                }
            } catch (\Throwable $e) {
                $logger = Log::for($this);
                if ($logger->isEnabled(Level::Debug)) {
                    $logger->debug(
                        '[ShutdownSequence] component shutdown error '
                        . get_debug_type($component) . ' msg=' . $e->getMessage(),
                    );
                }
            }
        }
    }
}
