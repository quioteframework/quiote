<?php
namespace Agavi\Execution;

use Psr\Http\Message\ServerRequestInterface;
use Agavi\Controller\AgaviController;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Exception\AgaviException;
use Agavi\View\AgaviView;
use Agavi\Util\AgaviToolkit;
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
        if(!$stack) {
            throw new AgaviException('SlotStack missing from request; ensure SlotMiddleware is registered.');
        }
    $key = $module . '/' . $action;
    $this->executionGuard->enter($stack, $key);
    try {
            $start = microtime(true);
            $cacheEnabled = AgaviConfig::get('core.use_cache', false) && (bool)getenv('AGAVI_SLOT_CACHE');
            $cacheKey = null; $cacheHit = false;
            // Build request data holder
            $rdh = null;
            if($parameters) {
                $rdh = new AgaviRequestDataHolder();
                foreach($parameters as $k=>$v) { $rdh->setParameter($k,$v); }
            }
            // Normalize output type to lowercase as configuration keys are lowercase
            $normalizedOutputType = $outputType !== null ? strtolower($outputType) : null;
            // Determine upfront which execution mode to use so we only create a legacy container if required.
            $actionInstance = $this->controller->createActionInstance($module, $action);
            if(!($actionInstance instanceof AgaviAction)) { throw new AgaviException('Slot action did not resolve to AgaviAction'); }
            // Hard break: container path removed. Always container-less execution.
            if(!$rdh) { $rdh = new AgaviRequestDataHolder(); }
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
                if(method_exists($actionInstance,'setAttribute')) { try { $actionInstance->setAttribute('is_slot', true); } catch(\Throwable) {} }
                // Early experimental path: execute simple action without full container
                $rd = $rdh ?? new AgaviRequestDataHolder();
                // Execute action via resolver for method-based verbs (execute|executeXxx)
                $rawViewName = $this->actionResolver->execute($actionInstance, strtoupper($parentRequest->getMethod() ?? 'GET'), $rd);
                $attributeSnapshot = [];
                if(method_exists($actionInstance,'getAttributes')) { try { $attributeSnapshot = $actionInstance->getAttributes(); } catch(\Throwable) { $attributeSnapshot = []; } }
                [$viewModule, $viewCanonical] = $this->viewNameResolver->resolve($module, $action, $rawViewName);
                $viewInstance = null; $result = '';
                if($viewCanonical !== AgaviView::NONE) {
                    $viewInstance = $this->viewFactory->create($viewModule,$viewCanonical,$module,$action,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$rd,$attributeSnapshot);
                    if(!$viewInstance) {
                        try { $viewInstance = $this->controller->createViewInstance($viewModule,$viewCanonical); } catch(\Throwable) {}
                        if($viewInstance) {
                            try { $vic = new \Agavi\Execution\ImmutableViewInitContext($this->controller->getContext(), $viewModule,$viewCanonical,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$module,$action,(array)$attributeSnapshot,$this->controller->getGlobalResponse()); $viewInstance->initialize($vic);} catch(\Throwable) {}
                        }
                    }
                    $method = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName());
                    if(!$viewInstance || !is_callable([$viewInstance,$method])) { $method = 'execute'; }
                    $res = $viewInstance?->$method($rd);
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
                    requestData: $rd,
                    content: (string)$result,
                );
                $this->lastContext = $ctx;
                return $ctx->content;
            } else { // non-simple
                if(method_exists($actionInstance,'setAttribute')) { try { $actionInstance->setAttribute('is_slot', true); } catch(\Throwable) {} }
                // Container-less path for non-simple actions (security + validation + view)
                $rd = $rdh ?? new AgaviRequestDataHolder();
                // Initialize action with lightweight context (mirrors ActionExecutor)
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
                $securityService = new SecurityService($this->controller);
                $decision = $securityService->decide($actionInstance);
                if($decision !== SecurityDecision::Allow) {
                    $key = $decision === SecurityDecision::LoginForward ? 'login' : 'secure';
                    // Build descriptor for system forward and execute like a fresh (simple) action
                    $httpMethod = strtoupper($parentRequest->getMethod() ?? 'GET');
                    $fwdDesc = $this->forwardService->createSystemForwardActionDescriptor($key, $httpMethod, $outputType ?? $this->controller->getOutputType()->getName());
                    try {
                        $fwdAction = $this->controller->createActionInstance($fwdDesc->module, $fwdDesc->action);
                        if(method_exists($fwdAction,'initialize')) {
                            $initCtx = new LightweightActionInitContext(
                                $this->controller->getContext(),
                                $fwdDesc->module,
                                $fwdDesc->action,
                                $fwdDesc->method,
                                $fwdDesc->outputType,
                                $rd,
                                $this->controller->getGlobalResponse()
                            );
                            $fwdAction->initialize($initCtx);
                        }
                        $rawViewName = $this->actionResolver->execute($fwdAction, $fwdDesc->method, $rd);
                        [$vm,$vn] = $this->viewNameResolver->resolve($fwdDesc->module, $fwdDesc->action, $rawViewName);
                        $viewInstance = null; $content = '';
                        if($vn !== AgaviView::NONE) {
                            $attrs = method_exists($fwdAction,'getAttributes')?(array)$fwdAction->getAttributes():[];
                            $viewInstance = $this->viewFactory->create($vm,$vn,$fwdDesc->module,$fwdDesc->action,$fwdDesc->outputType,$rd,$attrs);
                            if(!$viewInstance) { try { $viewInstance = $this->controller->createViewInstance($vm,$vn); } catch(\Throwable) {} }
                            if($viewInstance) { try { $vic = new \Agavi\Execution\ImmutableViewInitContext($this->controller->getContext(),$vm,$vn,$fwdDesc->outputType,$fwdDesc->module,$fwdDesc->action,$attrs,$this->controller->getGlobalResponse()); $viewInstance->initialize($vic);} catch(\Throwable) {} }
                            $methodExec = 'execute' . ucfirst($fwdDesc->outputType);
                            if(!$viewInstance || !is_callable([$viewInstance,$methodExec])) { $methodExec = 'execute'; }
                            $res = $viewInstance?->$methodExec($rd); if($res !== null) { $content = (string)$res; } elseif($viewInstance && method_exists($viewInstance,'getLayers') && method_exists($viewInstance,'renderLayers') && $viewInstance->getLayers()) { $layerContent = $viewInstance->renderLayers(); if($layerContent !== '') { $content = $layerContent; } }
                        }
                        $ctx = new ActionExecutionContext($fwdAction,$viewInstance,$fwdDesc->module,$fwdDesc->action,$fwdDesc->outputType,$rd,(string)$content,$vm ?? null,$vn ?? null,method_exists($fwdAction,'getAttributes')?(array)$fwdAction->getAttributes():[]);
                        $this->lastContext = $ctx; return $ctx->content;
                    } catch(\Throwable $fwdErr) {
                        // Fail closed: return empty string to avoid masking original security failure silently
                        $ctx = new ActionExecutionContext($actionInstance,null,$module,$action,$outputType ?? $this->controller->getOutputType()->getName(),$rd,'');
                        $this->lastContext = $ctx; return '';
                    }
                }
                // Validation
                $validationService = new ValidationService();
                $vres = $validationService->validate($actionInstance, $rd, $module, $action, 'Default');
                if(!$vres->ok) {
                    $rawViewName = $actionInstance->handleError($rd);
                    [$vm,$vn] = $this->viewNameResolver->resolve($module,$action,$rawViewName);
                    $viewInstance = null; $content = '';
                    if($vn !== AgaviView::NONE) {
                        $viewInstance = $this->viewFactory->create($vm,$vn,$module,$action,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$rd,method_exists($actionInstance,'getAttributes')?(array)$actionInstance->getAttributes():[]);
                        if(!$viewInstance) { try { $viewInstance = $this->controller->createViewInstance($vm,$vn); } catch(\Throwable) {} }
                        if($viewInstance) { try { $vic = new \Agavi\Execution\ImmutableViewInitContext($this->controller->getContext(),$vm,$vn,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$module,$action,method_exists($actionInstance,'getAttributes')?(array)$actionInstance->getAttributes():[],$this->controller->getGlobalResponse()); $viewInstance->initialize($vic);} catch(\Throwable) {} }
                        $methodExec = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName()); if(!$viewInstance || !is_callable([$viewInstance,$methodExec])) { $methodExec = 'execute'; }
                        $res = $viewInstance?->$methodExec($rd); if($res !== null) { $content = (string)$res; } elseif($viewInstance && method_exists($viewInstance,'getLayers') && method_exists($viewInstance,'renderLayers') && $viewInstance->getLayers()) { $layerContent = $viewInstance->renderLayers(); if($layerContent !== '') { $content = $layerContent; } }
                    }
                    $ctx = new ActionExecutionContext($actionInstance,$viewInstance,$module,$action,$outputType ?? $this->controller->getOutputType()->getName(),$rd,(string)$content,$vm,$vn);
                    $this->lastContext = $ctx; return $ctx->content;
                }
                // Execute action method
                $requestMethod = strtoupper($parentRequest->getMethod() ?? 'GET');
                $rawViewName = $this->actionResolver->execute($actionInstance, $requestMethod, $rd);
                [$vm,$vn] = $this->viewNameResolver->resolve($module,$action,$rawViewName);
                $viewInstance = null; $result = '';
                if($vn !== AgaviView::NONE) {
                    $attrs = method_exists($actionInstance,'getAttributes')?(array)$actionInstance->getAttributes():[];
                    $viewInstance = $this->viewFactory->create($vm,$vn,$module,$action,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$rd,$attrs);
                    if(!$viewInstance) { try { $viewInstance = $this->controller->createViewInstance($vm,$vn); } catch(\Throwable) {} }
                    if($viewInstance) { try { $vic = new \Agavi\Execution\ImmutableViewInitContext($this->controller->getContext(),$vm,$vn,strtolower(($outputType ?? $this->controller->getOutputType()->getName())),$module,$action,$attrs,$this->controller->getGlobalResponse()); $viewInstance->initialize($vic);} catch(\Throwable) {} }
                    $methodExec = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName()); if(!$viewInstance || !is_callable([$viewInstance,$methodExec])) { $methodExec = 'execute'; }
                    $res = $viewInstance?->$methodExec($rd); if($res !== null) { $result = (string)$res; } elseif($viewInstance && method_exists($viewInstance,'getLayers') && method_exists($viewInstance,'renderLayers') && $viewInstance->getLayers()) { $layerContent = $viewInstance->renderLayers(); if($layerContent !== '') { $result = $layerContent; } }
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
            $this->executionGuard->leave($stack);
        }
    }

    /**
     * Experimental API: identical to dispatch() but returns ActionExecutionContext alongside content.
     */
    public function dispatchWithContext(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): ActionExecutionContext
    {
        $content = $this->dispatch($parentRequest, $module, $action, $parameters, $outputType);
    if($this->lastContext) { return $this->lastContext; }
        // Fallback: synthesize minimal context when container path used
        return new ActionExecutionContext(
            action: $this->controller->createActionInstance($module,$action),
            view: null,
            module: $module,
            actionName: $action,
            outputType: $outputType ?? $this->controller->getOutputType()->getName(),
            requestData: new AgaviRequestDataHolder(),
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
            requestData: $ctx->requestData,
            content: $ctx->content,
            viewModuleName: $ctx->viewModuleName,
            viewName: $ctx->viewName,
            actionAttributes: $ctx->actionAttributes ?? [],
            parameters: $parameters
        );
    }
}
