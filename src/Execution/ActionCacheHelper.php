<?php
namespace Agavi\Execution;

use Agavi\Cache\ActionViewCache;
use Agavi\Execution\ActionExecutionContext;

final class ActionCacheHelper
{
    /**
     * Unified cache payload write.
     */
    public static function store(ActionViewCache $cache, ActionDescriptor $desc, ExecutionState $state, string $content, array $actionAttributes, bool $isSimple, ?int $ttl = null, ?string $userFingerprint = null): void
    {
    // Master switch: disable all action/view caching globally when core.cache_enabled = false (default off)
    if(!\Agavi\Config\AgaviConfig::get('core.cache_enabled', false)) { return; }
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
            ], $ttl, $userFingerprint);
        } catch(\Throwable) { /* ignore cache write errors */ }
    }

    /**
     * Raw read of cached payload (no hydration) – returns array payload or null.
     */
    public static function read(ActionViewCache $cache, ActionDescriptor $desc, ?string $userFingerprint = null): ?array
    {
    if(!\Agavi\Config\AgaviConfig::get('core.cache_enabled', false)) { return null; }
        try {
            // Attempt fingerprint-specific first; fallback to global if none.
            if($userFingerprint) {
                $payload = $cache->get($desc->module, $desc->action, $desc->outputType, $userFingerprint);
                if($payload) { return $payload; }
            }
            return $cache->get($desc->module, $desc->action, $desc->outputType) ?: null;
        } catch(\Throwable) { return null; }
    }

    /**
     * Hydrate ExecutionState and build an ActionExecutionContext from a payload.
     * Mutates $state (sets viewModule/viewName/cacheHit and validation flags if present).
     */
    public static function buildContextFromPayload(array $payload, ActionDescriptor $desc, ExecutionState $state, $actionInstance, \Agavi\Request\AgaviRequestDataHolder $requestData, ?string $contentOverride = null): ActionExecutionContext
    {
        $state->viewModule = $payload['view_module'] ?? $state->viewModule;
        $state->viewName = $payload['view_name'] ?? $state->viewName;
        $state->cacheHit = true;
        if(isset($payload['state']) && is_array($payload['state'])) {
            if(isset($payload['state']['validationDecision'])) {
                $state->validationDecision = match($payload['state']['validationDecision']) {
                    'passed' => ValidationDecision::passed(),
                    'failed' => ValidationDecision::failed($payload['state']['validationErrors'] ?? []),
                    default => ValidationDecision::pending(),
                };
            }
            $state->securityDecision = $payload['state']['securityDecision'] ?? $state->securityDecision;
        }
        $content = $contentOverride ?? ($payload['response_content'] ?? '');
        return new ActionExecutionContext(
            $actionInstance ?? (object)[],
            null, // view instance is not reconstructed on cache replay
            $desc->module,
            $desc->action,
            $desc->outputType,
            $requestData,
            (string)$content,
            $state->viewModule,
            $state->viewName,
            $payload['action_attributes'] ?? []
        );
    }
}
