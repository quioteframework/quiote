<?php
namespace Agavi\Execution;

/**
 * Mutable per-execution state (will eventually replace AgaviExecutionContainer's mutable fields).
 */
final class ExecutionState
{
    public function __construct(
        public bool $validationPerformed = false,
        public bool $validationSucceeded = true,
        public ?string $viewModule = null,
        public ?string $viewName = null,
        public array $actionAttributes = [],
        public bool $cacheHit = false,
            // Security decision placeholder: null (not evaluated), enum SecurityDecision
            public ?SecurityDecision $securityDecision = null,
        // Indicates a forward (login/secure) short-circuited execution before validation
    public bool $forwarded = false,
    // Routed module/action/outputType (duplicated from request attributes for convenience / caching keys)
    public ?string $module = null,
    public ?string $action = null,
    public ?string $outputType = null,
    // Optional metrics (TimingMiddleware)
    public ?array $metrics = null
    ) {}
}
