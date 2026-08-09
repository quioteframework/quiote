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

    /**
     * Attaches the immutable execution context and mirrors its outcome onto the mutable state.
     *
     * The view module and view name are copied across unconditionally; the action attributes
     * only when the context carries a non-empty set, so an action that produced none leaves
     * whatever the state already held intact.
     */
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

    /**
     * Returns the content produced by this dispatch.
     *
     * Empty when no context has been attached yet, or when the attached context carries
     * no content of its own.
     */
    public function getContent(): string
    {
        return $this->context->content ?? '';
    }
}
