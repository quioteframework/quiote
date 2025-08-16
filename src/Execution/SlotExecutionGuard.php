<?php
namespace Agavi\Execution;

use Agavi\Exception\AgaviException;

/**
 * SlotExecutionGuard centralizes recursion limit enforcement for slot dispatches.
 */
final class SlotExecutionGuard
{
    public function __construct(private int $limit) {}

    public function enter(SlotStack $stack, string $key): void
    {
        $stack->push($key);
        if ($stack->occurrences($key) > $this->limit) {
            throw new AgaviException('Slot recursion limit exceeded for ' . $key);
        }
    }

    public function leave(SlotStack $stack): void
    {
        $stack->pop();
    }
}
