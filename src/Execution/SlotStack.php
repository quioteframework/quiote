<?php
namespace Agavi\Execution;

/**
 * Stack tracking nested slot/sub-action executions to replace implicit recursion in AgaviExecutionContainer.
 */
final class SlotStack
{
    private array $stack = [];
    public function push(string $key): void { $this->stack[] = $key; }
    public function pop(): void { array_pop($this->stack); }
    public function depth(): int { return count($this->stack); }
    public function occurrences(string $key): int { return count(array_filter($this->stack, fn($k)=>$k===$key)); }
}
