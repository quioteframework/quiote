<?php
namespace Agavi\Execution;

/**
 * Mutable per-execution state (will eventually replace AgaviExecutionContainer's mutable fields).
 */
final class ExecutionState
{
    public function __construct(
    // Removed legacy validation booleans; rely solely on ValidationDecision.
        public ?string $viewModule = null,
        public ?string $viewName = null,
        public array $actionAttributes = [],
        public bool $cacheHit = false,
        // Security decision placeholder: null (not evaluated), enum SecurityDecision
        public ?SecurityDecision $securityDecision = null,
        // Indicates a forward (login/secure) short-circuited execution before validation
        public bool $forwarded = false,
        // New unified validation decision (pending|passed|failed)
    public ?ValidationDecision $validationDecision = null,
    // Forward count (security/login/secure forwards) to guard against infinite loops
    public int $forwardCount = 0,
        // Routed module/action/outputType (duplicated from request attributes for convenience / caching keys)
        public ?string $module = null,
        public ?string $action = null,
        public ?string $outputType = null,
        // Optional metrics (TimingMiddleware)
        public ?array $metrics = null
    ) {
    if ($this->validationDecision === null) {
            $this->validationDecision = ValidationDecision::pending();
        }
    }
}
