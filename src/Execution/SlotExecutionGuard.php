<?php
namespace Agavi\Execution;

use Agavi\Exception\AgaviException;

/**
 * SlotExecutionGuard centralizes recursion limit enforcement for slot dispatches.
 */
final readonly class SlotExecutionGuard
{
    public function __construct(private int $limit) {}

    /**
     * Push the key and throw if over the hard limit.
     */
    public function enter(SlotStack $stack, string $key): void
    {
        $stack->push($key);
        if ($stack->occurrences($key) > $this->limit) {
            throw new AgaviException('Slot recursion limit exceeded for ' . $key);
        }
    }

    /**
     * Remove the last pushed key.
     */
    public function leave(SlotStack $stack): void
    {
        $stack->pop();
    }

    /**
     * Non-throwing check: would pushing this key exceed the configured limit?
     * Useful to allow callers to fail soft (return empty) instead of throwing.
     */
    public function wouldExceed(SlotStack $stack, string $key): bool
    {
        $count = $stack->occurrences($key);
        return ($count + 1) > $this->limit;
    }
}
