<?php
namespace Agavi\Execution;

/**
 * Stack tracking nested slot/sub-action executions to replace implicit recursion in AgaviExecutionContainer.
 */
final class SlotStack
{
    private array $stack = [];
    // Transient per-request set of keys we've already warned about to avoid log spam
    private array $warnedKeys = [];
    public function push(string $key): void { $this->stack[] = $key; }
    public function pop(): void { array_pop($this->stack); }
    public function depth(): int { return count($this->stack); }
    public function occurrences(string $key): int { return count(array_filter($this->stack, fn($k)=>$k===$key)); }

    public function hasWarned(string $key): bool { return isset($this->warnedKeys[$key]); }
    public function markWarned(string $key): void { $this->warnedKeys[$key] = true; }
}
