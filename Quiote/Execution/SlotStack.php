<?php
namespace Quiote\Execution;

/**
 * Stack tracking nested slot/sub-action executions, so recursion depth is
 * explicit and boundable rather than implicit in the call stack.
 */
final class SlotStack
{
    /** @var string[] */
    private array $stack = [];
    // Transient per-request set of keys we've already warned about to avoid log spam
    /** @var array<string,bool> */
    private array $warnedKeys = [];
    // Original PSR-7 request before validation pruning - used by SlotDispatcher
    private ?\Psr\Http\Message\ServerRequestInterface $originalRequest = null;

    /** Marks the start of a nested slot execution by pushing its key onto the stack. */
    public function push(string $key): void { $this->stack[] = $key; }
    /** Marks the end of the innermost slot execution; popping an empty stack is a no-op. */
    public function pop(): void { array_pop($this->stack); }
    /** Returns how many slot executions are currently nested. */
    public function depth(): int { return count($this->stack); }
    /**
     * Returns how many times the given key appears anywhere on the stack.
     *
     * A count above one means the same slot is rendering itself, which is what
     * recursion guards test against before dispatching again.
     */
    public function occurrences(string $key): int { return count(array_filter($this->stack, fn($k)=>$k===$key)); }

    /** Reports whether a warning has already been recorded for this key during the current request. */
    public function hasWarned(string $key): bool { return isset($this->warnedKeys[$key]); }
    /** Records that a warning has been emitted for this key, so later checks can suppress duplicates. */
    public function markWarned(string $key): void { $this->warnedKeys[$key] = true; }

    /**
     * Stores the request as it stood before validation pruned its parameters.
     *
     * Slot dispatch reads this back so a nested action sees the parameters the parent
     * request arrived with rather than the reduced set validation left behind.
     */
    public function setOriginalRequest(\Psr\Http\Message\ServerRequestInterface $request): void
    {
        $this->originalRequest = $request;
    }

    /**
     * Returns the pre-validation request recorded for this stack.
     *
     * Null when nothing recorded one, which is the case for any dispatch that did not
     * pass through validation before reaching a slot.
     *
     * @internal Exists so {@see SlotDispatcher} can restore a parameter the slot overlay
     *           replaced. The request it returns has not been through validation, so its
     *           parameters carry raw client input; application code must read parameters
     *           through {@see \Quiote\Request\WebRequest::getParameter()} instead.
     */
    public function getOriginalRequest(): ?\Psr\Http\Message\ServerRequestInterface
    {
        return $this->originalRequest;
    }
}
