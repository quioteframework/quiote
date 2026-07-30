<?php
namespace Quiote\Execution;

/**
 * Aggregates mutable ExecutionState with the immutable ActionExecutionContext
 * for one top-level dispatch.
 */
final class ActionExecutionSession
{
    public ?ActionExecutionContext $context = null;

    public function __construct(public ExecutionState $state) {}

    public function setContext(ActionExecutionContext $ctx): void
    {
        $this->context = $ctx;
        // Sync view info + attributes into state for downstream usage.
        $this->state->viewModule = $ctx->viewModuleName;
        $this->state->viewName = $ctx->viewName;
        if($ctx->actionAttributes) {
            $this->state->actionAttributes = $ctx->actionAttributes;
        }
    }

    public function getContent(): string
    {
        return $this->context->content ?? '';
    }
}
