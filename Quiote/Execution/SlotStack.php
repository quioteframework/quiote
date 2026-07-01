<?php
namespace Quiote\Execution;

/**
 * Stack tracking nested slot/sub-action executions to replace implicit recursion in ExecutionContainer.
 */
final class SlotStack
{
    private array $stack = [];
    // Transient per-request set of keys we've already warned about to avoid log spam
    private array $warnedKeys = [];
    // Original PSR-7 request before validation pruning - used by SlotDispatcher
    private ?\Psr\Http\Message\ServerRequestInterface $originalRequest = null;

    public function push(string $key): void { $this->stack[] = $key; }
    public function pop(): void { array_pop($this->stack); }
    public function depth(): int { return count($this->stack); }
    public function occurrences(string $key): int { return count(array_filter($this->stack, fn($k)=>$k===$key)); }

    public function hasWarned(string $key): bool { return isset($this->warnedKeys[$key]); }
    public function markWarned(string $key): void { $this->warnedKeys[$key] = true; }

    public function setOriginalRequest(\Psr\Http\Message\ServerRequestInterface $request): void
    {
        $this->originalRequest = $request;
    }

    public function getOriginalRequest(): ?\Psr\Http\Message\ServerRequestInterface
    {
        return $this->originalRequest;
    }
}
