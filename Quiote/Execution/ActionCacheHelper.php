<?php
namespace Quiote\Execution;

use Quiote\Cache\ActionViewCache;
use Quiote\Execution\ActionExecutionContext;
use Quiote\Request\WebRequest;

final class ActionCacheHelper
{
    /**
     * Unified cache payload write.
     *
     * @param array<string, mixed> $actionAttributes
     */
    public static function store(ActionViewCache $cache, ActionDescriptor $desc, ExecutionState $state, string $content, array $actionAttributes, bool $isSimple, ?int $ttl = null, ?string $userFingerprint = null, ?string $locale = null): void
    {
    // Master switch: disable all action/view caching globally when core.cache_enabled = false (default off)
    if(!\Quiote\Config\Config::getBool('core.cache_enabled', false)) { return; }
    try {
            $cache->set($desc->module, $desc->action, $desc->outputType, [
                'view_module' => $state->viewModule,
                'view_name' => $state->viewName,
                'action_attributes' => $actionAttributes,
                'response_content' => $content,
                'descriptor' => [
                    'module' => $desc->module,
                    'action' => $desc->action,
                    'method' => $desc->method,
                    'outputType' => $desc->outputType,
                    'isSimple' => $isSimple,
                ],
                'state' => [
                    'validationDecision' => $state->validationDecision?->state,
                    'validationErrors' => $state->validationDecision?->errors,
                    'viewModule' => $state->viewModule,
                    'viewName' => $state->viewName,
                    'securityDecision' => $state->securityDecision,
                ],
                'user_fingerprint' => $userFingerprint,
            ], $ttl, $userFingerprint, $locale);
        } catch(\Throwable) { /* ignore cache write errors */ }
    }

    /**
     * Raw read of cached payload (no hydration) – returns array payload or null.
     *
     * Reads exactly the partition it was asked for. There used to be a fallback
     * to the unpartitioned entry when the per-user lookup missed, which meant a
     * partitioned read could still be answered with content rendered for a
     * different identity -- defeating the partitioning on every cold miss.
     * A miss in a partition is a miss.
     *
     * @return array<string, mixed>|null
     */
    public static function read(ActionViewCache $cache, ActionDescriptor $desc, ?string $userFingerprint = null, ?string $locale = null): ?array
    {
    if(!\Quiote\Config\Config::getBool('core.cache_enabled', false)) { return null; }
        try {
            return $cache->get($desc->module, $desc->action, $desc->outputType, $userFingerprint, $locale) ?: null;
        } catch(\Throwable) { return null; }
    }

    /**
     * Hydrate ExecutionState and build an ActionExecutionContext from a payload.
     * Mutates $state (sets viewModule/viewName/cacheHit and validation flags if present).
     *
     * @param array<string, mixed> $payload
     */
    public static function buildContextFromPayload(array $payload, ActionDescriptor $desc, ExecutionState $state, ?\Quiote\Action\Action $actionInstance, WebRequest $request, ?string $contentOverride = null): ActionExecutionContext
    {
        if ($actionInstance === null) {
            // A cache hit is only ever recorded once the action instance has been
            // successfully created and its isCacheable() consulted, so a null instance
            // here means the cache/dispatch invariant was violated rather than a normal
            // "no action yet" state. Fail loudly instead of feeding a bogus placeholder
            // object into ActionExecutionContext, which requires a real Action.
            throw new \RuntimeException('ActionCacheHelper::buildContextFromPayload() requires a non-null action instance for a cache hit');
        }
        $viewModule = $payload['view_module'] ?? $state->viewModule;
        if ($viewModule !== null && !is_string($viewModule)) {
            throw new \UnexpectedValueException(sprintf('Cached "view_module" must be a string or null, %s given.', get_debug_type($viewModule)));
        }
        $state->viewModule = $viewModule;

        $viewName = $payload['view_name'] ?? $state->viewName;
        if ($viewName !== null && !is_string($viewName)) {
            throw new \UnexpectedValueException(sprintf('Cached "view_name" must be a string or null, %s given.', get_debug_type($viewName)));
        }
        $state->viewName = $viewName;

        $state->cacheHit = true;
        if(isset($payload['state']) && is_array($payload['state'])) {
            if(isset($payload['state']['validationDecision'])) {
                $validationErrors = $payload['state']['validationErrors'] ?? [];
                if (!is_array($validationErrors)) {
                    throw new \UnexpectedValueException(sprintf('Cached "validationErrors" must be an array, %s given.', get_debug_type($validationErrors)));
                }
                $state->validationDecision = match($payload['state']['validationDecision']) {
                    'passed' => ValidationDecision::passed(),
                    'failed' => ValidationDecision::failed($validationErrors),
                    default => ValidationDecision::pending(),
                };
            }
            $securityDecision = $payload['state']['securityDecision'] ?? $state->securityDecision;
            if ($securityDecision !== null && !$securityDecision instanceof SecurityDecision) {
                throw new \UnexpectedValueException(sprintf('Cached "securityDecision" must be a SecurityDecision or null, %s given.', get_debug_type($securityDecision)));
            }
            $state->securityDecision = $securityDecision;
        }
        $content = $contentOverride ?? ($payload['response_content'] ?? '');
        if (!is_string($content)) {
            throw new \UnexpectedValueException(sprintf('Cached "response_content" must be a string, %s given.', get_debug_type($content)));
        }
        $rawActionAttributes = $payload['action_attributes'] ?? [];
        if (!is_array($rawActionAttributes)) {
            throw new \UnexpectedValueException(sprintf('Cached "action_attributes" must be an array, %s given.', get_debug_type($rawActionAttributes)));
        }
        $actionAttributes = [];
        foreach ($rawActionAttributes as $attributeKey => $attributeValue) {
            if (!is_string($attributeKey)) {
                throw new \UnexpectedValueException(sprintf('Cached "action_attributes" keys must be strings, %s given.', get_debug_type($attributeKey)));
            }
            $actionAttributes[$attributeKey] = $attributeValue;
        }
        return new ActionExecutionContext(
            $actionInstance,
            null, // view instance is not reconstructed on cache replay
            $desc->module,
            $desc->action,
            $desc->outputType,
            $request,
            $content,
            $state->viewModule,
            $state->viewName,
            $actionAttributes
        );
    }
}
