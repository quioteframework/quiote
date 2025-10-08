<?php
namespace Agavi\Execution;

use Psr\Http\Message\ServerRequestInterface;
use Agavi\Controller\AgaviController;
use Agavi\Exception\AgaviException;
use Agavi\View\AgaviView;
use Agavi\Execution\ActionExecutionContext;
use Agavi\Execution\SecurityService;
use Agavi\Execution\SecurityDecision;
use Agavi\Execution\ValidationService;
use Agavi\Execution\ForwardService;
// ViewResolver removed; SlotDispatcher uses ViewNameResolver directly
use Agavi\Execution\ViewNameResolver;
use Agavi\Execution\LightweightActionInitContext;
use Agavi\Execution\ActionResolver;
use Agavi\Action\AgaviAction;
use Agavi\Execution\SlotContent;
use Agavi\Cache\CacheManager;
use Agavi\Config\AgaviConfig;
use Agavi\Request\AgaviWebRequest;
use Agavi\Logging\AgaviDebugLogger;

/**
 * SlotDispatcher executes sub-actions ("slots") via container-less execution only.
 */
/**
 * Dynamic optional action extension points used via method_exists():
 * @method int|null slotCacheTtlSeconds()
 * @method array slotCacheTags(array $parameters = [])
 */
class SlotDispatcher
{
    public const RECURSION_LIMIT = 10; // mirrors previous static guard
    private ?ActionExecutionContext $lastContext = null;

    public function __construct(private AgaviController $controller, private ?ActionResolver $actionResolver = null, private ?SlotExecutionGuard $executionGuard = null, private ?ViewNameResolver $viewNameResolver = null, private ?ForwardService $forwardService = null, private ?ViewFactory $viewFactory = null) {
        // Initialize pure resolver
        $this->viewNameResolver ??= new ViewNameResolver();
        $this->actionResolver ??= new ActionResolver();
        $this->executionGuard ??= new SlotExecutionGuard(self::RECURSION_LIMIT);
        $this->forwardService ??= new ForwardService($controller);
        $this->viewFactory ??= new ViewFactory($controller);
    }

    /**
     * Dispatch a slot (sub-action) and return its response content.
     * @param ServerRequestInterface $parentRequest The parent PSR request containing SlotStack attribute.
     * @param string $module Module name.
     * @param string $action Action name.
     * @param array $parameters Optional associative array of request parameters for the slot.
     * @param string|null $outputType Optional output type override.
     */
    public function dispatch(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): string
    {
        /** @var SlotStack|null $stack */
        $stack = $parentRequest->getAttribute(SlotStack::class);
        // Build canonical key for this slot early so diagnostics and guards can reference it
        $key = $module . '/' . $action;
        $logExceptions = getenv('AGAVI_DEBUG_SLOT_EXCEPTIONS');
        $dbg = getenv('AGAVI_DEBUG_SLOT_DISPATCH');
        if ($dbg) {
            try {
                $pid = spl_object_id($parentRequest);
                $has = $stack ? '1' : '0';
                AgaviDebugLogger::debug(sprintf('[SlotDisp] dispatch parentRequest id=%d slotstack=%s key=%s', $pid, $has, $key), $this->controller->getContext());
            } catch (\Throwable $_e) {
                AgaviDebugLogger::debug('[SlotDisp] dispatch (no request id available)', $this->controller->getContext());
            }
        }
        if(!$stack) {
            throw new AgaviException('SlotStack missing from request; ensure SlotMiddleware is registered.');
        }
        // Soft-guard: if the next push would exceed the configured limit, fail soft
        // to prevent runaway rendering loops; emit a single log per key per request.
        try {
            if ($this->executionGuard->wouldExceed($stack, $key)) {
                if (!$stack->hasWarned($key)) {
                    $stack->markWarned($key);
                    if ($dbg) {
                        try {
                            AgaviDebugLogger::debug(sprintf('[SlotDisp] recursion guard triggered for key=%s parentRequest id=%d', $key, spl_object_id($parentRequest)), $this->controller->getContext());
                        } catch(\Throwable) {
                            AgaviDebugLogger::debug('[SlotDisp] recursion guard triggered for key=' . $key, $this->controller->getContext());
                        }
                    }
                }
                // Fail closed: return empty content instead of throwing to keep rendering going.
                return '';
            }
        } catch (\Throwable $_e) {
            // If guard check fails for any reason, continue and let enter() enforce the hard limit.
        }
    $this->executionGuard->enter($stack, $key);
    try {
            $start = microtime(true);
            $cacheEnabled = AgaviConfig::get('core.use_cache', false) && (bool)getenv('AGAVI_SLOT_CACHE');
            $cacheKey = null; $cacheHit = false;
            // Build request data holder: apply slot parameters via overlay (save originals, restore after dispatch).
            $rdh = null; $overlayApplied = false; $originals = [];
            if($parameters) {
                try { $rdh = $this->controller->getContext()->getRequest(); } catch(\Throwable) { $rdh = null; }
                if(!($rdh instanceof AgaviWebRequest)) { throw new \RuntimeException('Canonical AgaviWebRequest missing when applying slot parameters'); }
                foreach($parameters as $k=>$v) {
                    if(!array_key_exists($k, $originals)) { $originals[$k] = $rdh->getParameter($k); }
                    $rdh->setParameter($k, $v);
                }
                $overlayApplied = true;
                if(getenv('AGAVI_DEBUG_SLOT_DISPATCH')) {
                    try { \Agavi\Logging\AgaviDebugLogger::debug('[SlotDisp] overlay_applied key=' . $key . ' params=' . json_encode($parameters, JSON_UNESCAPED_SLASHES), $this->controller->getContext()); } catch(\Throwable) {}
                }
            }
            // Normalize output type to lowercase as configuration keys are lowercase
            $normalizedOutputType = $outputType !== null ? strtolower($outputType) : null;
            // Determine upfront which execution mode to use so we only create a legacy container if required.
            $actionInstance = $this->controller->createActionInstance($module, $action);
            if(!($actionInstance instanceof AgaviAction)) { throw new AgaviException('Slot action did not resolve to AgaviAction'); }
            // Hard break: container path removed. Always container-less execution.
            if(!$rdh) {
                try { $rdh = $this->controller->getContext()->getRequest(); } catch(\Throwable) { $rdh = null; }
                if(!($rdh instanceof AgaviWebRequest)) { throw new \RuntimeException('Canonical AgaviWebRequest missing in SlotDispatcher::dispatch (simple)'); }
            }
            if($cacheEnabled) {
                $normalizedOutputType = $outputType !== null ? strtolower($outputType) : $this->controller->getOutputType()->getName();
                // Tag/version support: actions may expose slotCacheTags(array $params): array
                $tags = [];
                if(method_exists($actionInstance,'slotCacheTags')) { // dynamic optional
                    try { $tags = (array)call_user_func([$actionInstance,'slotCacheTags'], $parameters); } catch(\Throwable) { $tags = []; }
                }
                $tagSuffix = '';
                if($tags) {
                    $versions = [];
                    foreach($tags as $t) {
                        $safe = preg_replace('/[^a-z0-9:_-]/i','_', (string)$t);
                        try { $versions[] = CacheManager::getNamespaceVersion('slot_tag:' . $safe); } catch(\Throwable) { $versions[] = '0'; }
                    }
                    $tagSuffix = ':' . implode('.', $versions);
                }
                $cacheKey = 'slot:' . strtolower($module) . ':' . strtolower($action) . ':' . $normalizedOutputType . $tagSuffix . ':' . md5(json_encode($parameters));
                try { $cached = CacheManager::getCache()->get($cacheKey); if(is_string($cached)) { $cacheHit = true; return $cached; } } catch(\Throwable) {}
            }
            if ($actionInstance->isSimple()) {
                // Mark action as slot for downstream views/layout selection (container-less compatibility)
                if(method_exists($actionInstance,'setAttribute')) { 
                    try { 
                        AgaviDebugLogger::debug('[SlotDispatcher] Setting is_slot=true on simple action ' . get_class($actionInstance), $this->controller->getContext());
                        $actionInstance->setAttribute('is_slot', true);
                        AgaviDebugLogger::debug('[SlotDispatcher] is_slot set, checking: ' . ($actionInstance->hasAttribute('is_slot') ? 'found' : 'not found'), $this->controller->getContext());
                    } catch(\Throwable $e) { 
                        AgaviDebugLogger::debug('[SlotDispatcher] Failed to set is_slot attribute: ' . $e->getMessage(), $this->controller->getContext());
                    } 
                }
                // Early experimental path: execute simple action without full container
                $rd = $rdh ?? (function($self){ try { return $self->controller->getContext()->getRequest(); } catch(\Throwable) { return null; } })($this);
                if(!($rd instanceof AgaviWebRequest)) { throw new \RuntimeException('Canonical AgaviWebRequest missing in SlotDispatcher simple action path'); }
                // Execute action via resolver for method-based verbs (execute|executeXxx)
                try {
                    $rawViewName = $this->actionResolver->execute($actionInstance, strtoupper($parentRequest->getMethod() ?? 'GET'), $rd);
                } catch(\Throwable $e) {
                    if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'simple_action_execute'); }
                    throw $e;
                }
                $attributeSnapshot = [];
                if(method_exists($actionInstance,'getAttributes')) { try { $attributeSnapshot = $actionInstance->getAttributes(); } catch(\Throwable) { $attributeSnapshot = []; } }
                [$viewModule, $viewCanonical] = $this->viewNameResolver->resolve($module, $action, $rawViewName);
                $viewInstance = null; $result = '';
                if($viewCanonical !== AgaviView::NONE) {
                    try {
                        $viewInstance = $this->viewFactory->create($viewModule,$viewCanonical,$module,$action,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$rd,$attributeSnapshot);
                    } catch(\Throwable $e) {
                        if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'simple_view_factory_create'); }
                        throw $e;
                    }
                    if(!$viewInstance) {
                        try { $viewInstance = $this->controller->createViewInstance($viewModule,$viewCanonical); } catch(\Throwable) {}
                        if($viewInstance) {
                            try { $vic = new \Agavi\Execution\ImmutableViewInitContext($this->controller->getContext(), $viewModule,$viewCanonical,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$module,$action,(array)$attributeSnapshot,$this->controller->getGlobalResponse()); $viewInstance->initialize($vic);} catch(\Throwable) {}
                        }
                    }
                    $method = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName());
                    if(!$viewInstance || !is_callable([$viewInstance,$method])) { $method = 'execute'; }
                    try {
                        $res = $viewInstance?->$method($rd);
                    } catch(\Throwable $e) {
                        if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'simple_view_execute'); }
                        throw $e;
                    }
                    if($res !== null) { $result = (string)$res; }
                    elseif($viewInstance && method_exists($viewInstance,'getLayers') && method_exists($viewInstance,'renderLayers') && $viewInstance->getLayers()) {
                        $layerContent = $viewInstance->renderLayers(); if($layerContent !== '') { $result = $layerContent; }
                    }
                }
                if($cacheEnabled && !$cacheHit) {
                    $ttl = null; if(method_exists($actionInstance,'slotCacheTtlSeconds')) { try { $ttl = (int)call_user_func([$actionInstance,'slotCacheTtlSeconds']); } catch(\Throwable) { $ttl = null; } }
                    try { CacheManager::getCache()->set($cacheKey, $result, $ttl ?: null); } catch(\Throwable){}
                }
                // Build execution context (presently unused by caller, but enables future hooks)
                // Build context but only return content (use dispatchWithContext() for caller access)
                $viewOutputType = $outputType ?? $this->controller->getOutputType()->getName();
                $ctx = new ActionExecutionContext(
                    action: $actionInstance,
                    view: $viewInstance,
                    module: $module,
                    actionName: $action,
                    outputType: $viewOutputType,
                    request: $rd,
                    content: (string)$result,
                );
                $this->lastContext = $ctx;
                return $ctx->content;
            } else { // non-simple
                // Container-less path for non-simple actions (security + validation + view)
                $rd = $rdh ?? (function($self){ try { return $self->controller->getContext()->getRequest(); } catch(\Throwable) { return null; } })($this);
                if(!($rd instanceof AgaviWebRequest)) { throw new \RuntimeException('Canonical AgaviWebRequest missing in SlotDispatcher non-simple action path'); }
                // Initialize action with lightweight context (mirrors ActionExecutor)
                try {
                    $lwCtx = new LightweightActionInitContext(
                        $this->controller->getContext(),
                        $module,
                        $action,
                        strtoupper($parentRequest->getMethod() ?? 'GET'),
                        strtolower(($outputType ?? $this->controller->getOutputType()->getName())),
                        $rd,
                        $this->controller->getGlobalResponse()
                    );
                    $actionInstance->initialize($lwCtx);
                } catch(\Throwable $e) {
                    if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_action_initialize'); }
                    throw $e;
                }
                
                // Mark action as slot AFTER initialization (when initContext exists)
                if(method_exists($actionInstance,'setAttribute')) { 
                    try { 
                        $actionInstance->setAttribute('is_slot', true);
                    } catch(\Throwable $e) { 
                        AgaviDebugLogger::debug('[SlotDispatcher] Failed to set is_slot attribute: ' . $e->getMessage(), $this->controller->getContext());
                    } 
                }
                $securityService = new SecurityService($this->controller);
                $decision = $securityService->decide($actionInstance);
                if($decision !== SecurityDecision::Allow) {
                    // Security denied for slot execution. Rendering the full system
                    // forward (login/secure) would produce a full page layout which
                    // itself renders slots (including the current one) and can
                    // therefore cause unbounded recursion during slot dispatch.
                    // For slot dispatches we fail closed: return empty content and
                    // record a small diagnostic context so callers can inspect the
                    // lastContext if needed.
                    try { AgaviDebugLogger::debug(sprintf('[SlotDisp] security denied for slot %s/%s during slot dispatch - returning empty content', $module, $action), $this->controller->getContext()); } catch(\Throwable) {}
                    $ctx = new ActionExecutionContext($actionInstance, null, $module, $action, $outputType ?? $this->controller->getOutputType()->getName(), $rd, '');
                    $this->lastContext = $ctx;
                    return $ctx->content;
                }
                // Validation
                $validationService = new ValidationService();
                try {
                    $vres = $validationService->validate($actionInstance, $rd, $module, $action, 'Default');
                } catch(\Throwable $e) {
                    if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_validation'); }
                    throw $e;
                }
                if(!$vres->ok) {
                    try {
                        $rawViewName = $actionInstance->handleError($rd);
                    } catch(\Throwable $e) {
                        if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_handle_error'); }
                        throw $e;
                    }
                    [$vm,$vn] = $this->viewNameResolver->resolve($module,$action,$rawViewName);
                    $viewInstance = null; $content = '';
                    if($vn !== AgaviView::NONE) {
                        try {
                            $viewInstance = $this->viewFactory->create($vm,$vn,$module,$action,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$rd,method_exists($actionInstance,'getAttributes')?(array)$actionInstance->getAttributes():[]);
                        } catch(\Throwable $e) {
                            if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_error_view_factory_create'); }
                            throw $e;
                        }
                        if(!$viewInstance) { try { $viewInstance = $this->controller->createViewInstance($vm,$vn); } catch(\Throwable) {} }
                        if($viewInstance) { try { $vic = new \Agavi\Execution\ImmutableViewInitContext($this->controller->getContext(),$vm,$vn,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$module,$action,method_exists($actionInstance,'getAttributes')?(array)$actionInstance->getAttributes():[],$this->controller->getGlobalResponse()); $viewInstance->initialize($vic);} catch(\Throwable) {} }
                        $methodExec = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName()); if(!$viewInstance || !is_callable([$viewInstance,$methodExec])) { $methodExec = 'execute'; }
                        try {
                            $res = $viewInstance?->$methodExec($rd);
                        } catch(\Throwable $e) {
                            if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_error_view_execute'); }
                            throw $e;
                        }
                        if($res !== null) { $content = (string)$res; } elseif($viewInstance && method_exists($viewInstance,'getLayers') && method_exists($viewInstance,'renderLayers') && $viewInstance->getLayers()) { $layerContent = $viewInstance->renderLayers(); if($layerContent !== '') { $content = $layerContent; } }
                    }
                    $ctx = new ActionExecutionContext($actionInstance,$viewInstance,$module,$action,$outputType ?? $this->controller->getOutputType()->getName(),$rd,(string)$content,$vm,$vn);
                    $this->lastContext = $ctx; return $ctx->content;
                }
                // Execute action method
                $requestMethod = strtoupper($parentRequest->getMethod() ?? 'GET');
                try {
                    $rawViewName = $this->actionResolver->execute($actionInstance, $requestMethod, $rd);
                } catch(\Throwable $e) {
                    if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_action_execute'); }
                    throw $e;
                }
                [$vm,$vn] = $this->viewNameResolver->resolve($module,$action,$rawViewName);
                $viewInstance = null; $result = '';
                if($vn !== AgaviView::NONE) {
                    $attrs = method_exists($actionInstance,'getAttributes')?(array)$actionInstance->getAttributes():[];
                    try {
                        $viewInstance = $this->viewFactory->create($vm,$vn,$module,$action,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$rd,$attrs);
                    } catch(\Throwable $e) {
                        if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_view_factory_create'); }
                        throw $e;
                    }
                    if(!$viewInstance) { try { $viewInstance = $this->controller->createViewInstance($vm,$vn); } catch(\Throwable) {} }
                    if($viewInstance) { try { $vic = new \Agavi\Execution\ImmutableViewInitContext($this->controller->getContext(),$vm,$vn,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$module,$action,$attrs,$this->controller->getGlobalResponse()); $viewInstance->initialize($vic);} catch(\Throwable) {} }
                    $methodExec = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName()); if(!$viewInstance || !is_callable([$viewInstance,$methodExec])) { $methodExec = 'execute'; }
                    try {
                        $res = $viewInstance?->$methodExec($rd);
                    } catch(\Throwable $e) {
                        if($logExceptions) { $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_view_execute'); }
                        throw $e;
                    }
                    if($res !== null) { $result = (string)$res; } elseif($viewInstance && method_exists($viewInstance,'getLayers') && method_exists($viewInstance,'renderLayers') && $viewInstance->getLayers()) { $layerContent = $viewInstance->renderLayers(); if($layerContent !== '') { $result = $layerContent; } }
                }
                if($cacheEnabled && !$cacheHit) {
                    $ttl = null; if(method_exists($actionInstance,'slotCacheTtlSeconds')) { try { $ttl = (int)call_user_func([$actionInstance,'slotCacheTtlSeconds']); } catch(\Throwable) { $ttl = null; } }
                    try { CacheManager::getCache()->set($cacheKey, $result, $ttl ?: null); } catch(\Throwable){}
                }
                $attrsFinal = method_exists($actionInstance,'getAttributes')?(array)$actionInstance->getAttributes():[];
                $ctx = new ActionExecutionContext($actionInstance,$viewInstance,$module,$action,$outputType ?? $this->controller->getOutputType()->getName(),$rd,(string)$result,$vm,$vn,$attrsFinal);
                $this->lastContext = $ctx; return $ctx->content;
            }
        } finally {
            // Restore original parameters if overlay applied
            if(isset($overlayApplied) && $overlayApplied && isset($rdh) && $rdh instanceof AgaviWebRequest) {
                foreach($originals as $k=>$v) {
                    if($v === null) {
                        // Parameter didn't exist before overlay; remove if current matches overlay value.
                        // We can't know overlay value without storing; accept leaving value as is if mismatch risk.
                        // Safer: remove unconditionally when original null and key exists.
                        try { $rdh->removeParameter($k); } catch(\Throwable) {}
                    } else {
                        try { $rdh->setParameter($k, $v); } catch(\Throwable) {}
                    }
                }
            }
            $this->executionGuard->leave($stack);
        }
    }

    private function logSlotException(\Throwable $e, string $module, string $action, array $parameters, string $phase): void
    {
        try {
            $payload = json_encode([
                'phase' => $phase,
                'module' => $module,
                'action' => $action,
                'parameters' => $parameters,
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $this->truncateTrace($e->getTraceAsString()),
                'time' => date('c'),
            ]);
            \error_log('SLOT_EXCEPTION ' . $payload);
        } catch(\Throwable) {
            // Never mask original exception
        }
    }

    private function truncateTrace(string $trace, int $max = 8000): string
    {
        if(strlen($trace) <= $max) { return $trace; }
        return substr($trace, 0, $max) . '... [truncated]';
    }

    /**
     * Experimental API: identical to dispatch() but returns ActionExecutionContext alongside content.
     */
    public function dispatchWithContext(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): ActionExecutionContext
    {
        $content = $this->dispatch($parentRequest, $module, $action, $parameters, $outputType);
    if($this->lastContext) { return $this->lastContext; }
        // Fallback: synthesize minimal context when container path used
        $sharedRequest = null;
        try { $sharedRequest = $this->controller->getContext()->getRequest(); } catch(\Throwable) { $sharedRequest = null; }
        if(!($sharedRequest instanceof AgaviWebRequest)) { throw new \RuntimeException('Canonical AgaviWebRequest missing in SlotDispatcher::dispatchWithContext fallback'); }
        return new ActionExecutionContext(
            action: $this->controller->createActionInstance($module,$action),
            view: null,
            module: $module,
            actionName: $action,
            outputType: $outputType ?? $this->controller->getOutputType()->getName(),
            request: $sharedRequest,
            content: $content,
        );
    }

    /**
     * New API: dispatch and return SlotContent value object instead of raw string.
     */
    public function dispatchSlotContent(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): SlotContent
    {
        $ctx = $this->dispatchWithContext($parentRequest, $module, $action, $parameters, $outputType);
        return new SlotContent($module, $action, $ctx->outputType, $ctx->content, $parameters);
    }

    /**
     * Experimental: dispatch slot and return SlotExecutionContext (immutable) for richer metadata.
     */
    public function dispatchSlotContext(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): SlotExecutionContext
    {
        $ctx = $this->dispatchWithContext($parentRequest, $module, $action, $parameters, $outputType);
        return new SlotExecutionContext(
            action: $ctx->action,
            view: $ctx->view,
            module: $ctx->module,
            actionName: $ctx->actionName,
            outputType: $ctx->outputType,
            request: $ctx->request,
            content: $ctx->content,
            viewModuleName: $ctx->viewModuleName,
            viewName: $ctx->viewName,
            actionAttributes: $ctx->actionAttributes ?? [],
            parameters: $parameters
        );
    }
}
