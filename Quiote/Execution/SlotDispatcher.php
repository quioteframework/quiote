<?php

namespace Quiote\Execution;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Controller\Controller;
use Quiote\Exception\QuioteException;
use Quiote\View\View;
use Quiote\Execution\ActionExecutionContext;
use Quiote\Execution\SecurityService;
use Quiote\Execution\SecurityDecision;
use Quiote\Execution\ValidationService;
use Quiote\Execution\ForwardService;
// ViewResolver removed; SlotDispatcher uses ViewNameResolver directly
use Quiote\Execution\ViewNameResolver;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Execution\ActionResolver;
use Quiote\Action\Action;
use Quiote\Execution\SlotContent;
use Quiote\Cache\CacheManager;
use Quiote\Config\Config;
use Quiote\Request\WebRequest;

/**
 * SlotDispatcher executes sub-actions ("slots") via container-less execution only.
 */
/**
 * Dynamic optional action extension points used via method_exists():
 * @method int|null slotCacheTtlSeconds()
 * @method array<int, string> slotCacheTags(array<string, mixed> $parameters = [])
 */
class SlotDispatcher
{
    public const RECURSION_LIMIT = 10; // mirrors previous static guard
    private ?ActionExecutionContext $lastContext = null;

    private readonly ActionResolver $actionResolver;
    private readonly SlotExecutionGuard $executionGuard;
    private readonly ViewNameResolver $viewNameResolver;
    private readonly ViewFactory $viewFactory;

    // Retained only to keep the constructor signature stable for callers that
    // inject it; nothing in this class reads it back yet.
    private ?ForwardService $forwardService = null;

    public function __construct(private readonly Controller $controller, ?ActionResolver $actionResolver = null, ?SlotExecutionGuard $executionGuard = null, ?ViewNameResolver $viewNameResolver = null, ?ForwardService $forwardService = null, ?ViewFactory $viewFactory = null)
    {
        // Initialize pure resolver
        $this->viewNameResolver = $viewNameResolver ?? new ViewNameResolver();
        $this->actionResolver = $actionResolver ?? new ActionResolver();
        $this->executionGuard = $executionGuard ?? new SlotExecutionGuard(self::RECURSION_LIMIT);
        $this->forwardService ??= $forwardService ?? new ForwardService($controller);
        $this->viewFactory = $viewFactory ?? new ViewFactory($controller);
    }

    /**
     * Action attribute names are always strings by contract; re-key defensively so a
     * stray int-keyed entry from AttributeHolder internals can never desync consumers
     * (ViewFactory::create(), ImmutableViewInitContext, ActionExecutionContext) that
     * index this snapshot by name.
     *
     * @param array<int|string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function normalizeAttributeKeys(array $attributes): array
    {
        return array_combine(
            array_map('strval', array_keys($attributes)),
            array_values($attributes)
        );
    }

    /**
     * Dispatch a slot (sub-action) and return its response content.
     * @param ServerRequestInterface $parentRequest The parent PSR request containing SlotStack attribute.
     * @param string $module Module name.
     * @param string $action Action name.
     * @param array<string, mixed> $parameters Optional associative array of request parameters for the slot.
     * @param ?string $outputType Optional output type override.
     */
    public function dispatch(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): string
    {
        /** @var ?SlotStack $stack */
        $stack = $parentRequest->getAttribute(SlotStack::class);
        // Build canonical key for this slot early so diagnostics and guards can reference it
        $key = $module . '/' . $action;
        $logger = \Quiote\Logging\Log::for($this);
        $logExceptions = $logger->isEnabled(\Quiote\Logging\Level::Debug);
        $dbg = $logger->isEnabled(\Quiote\Logging\Level::Debug);
        if ($dbg) {
            try {
                $pid = spl_object_id($parentRequest);
                $has = $stack ? '1' : '0';
                $logger->debug(sprintf('[SlotDisp] dispatch parentRequest id=%d slotstack=%s key=%s', $pid, $has, $key));
            } catch (\Throwable) {
                $logger->debug('[SlotDisp] dispatch (no request id available)');
            }
        }
        if (!$stack) {
            throw new QuioteException('SlotStack missing from request; ensure SlotMiddleware is registered.');
        }
        // Soft-guard: if the next push would exceed the configured limit, fail soft
        // to prevent runaway rendering loops; emit a single log per key per request.
        try {
            if ($this->executionGuard->wouldExceed($stack, $key)) {
                if (!$stack->hasWarned($key)) {
                    $stack->markWarned($key);
                    if ($dbg) {
                        try {
                            $logger->debug(sprintf('[SlotDisp] recursion guard triggered for key=%s parentRequest id=%d', $key, spl_object_id($parentRequest)));
                        } catch (\Throwable) {
                            $logger->debug('[SlotDisp] recursion guard triggered for key=' . $key);
                        }
                    }
                }
                // Fail closed: return empty content instead of throwing to keep rendering going.
                return '';
            }
        } catch (\Throwable) {
            // If guard check fails for any reason, continue and let enter() enforce the hard limit.
        }
        $this->executionGuard->enter($stack, $key);
        try {
            $start = microtime(true);
            $cacheEnabled = Config::getBool('core.use_cache', false) && (bool)getenv('QUIOTE_SLOT_CACHE');
            $cacheKey = null;
            $cacheHit = false;
            // Build request data holder: apply slot parameters via overlay (save originals, restore after dispatch).
            $rdh = null;
            $overlayApplied = false;
            $originals = [];
            if ($parameters) {
                try {
                    $rdh = $this->controller->getContext()->getRequest();
                } catch (\Throwable) {
                    $rdh = null;
                }
                if (!($rdh instanceof WebRequest)) {
                    throw new \RuntimeException('Canonical WebRequest missing when applying slot parameters');
                }
                // Get original PSR-7 request from SlotStack (saved before validation pruning)
                $originalRequest = $stack->getOriginalRequest();
                foreach ($parameters as $k => $v) {
                    if (!array_key_exists($k, $originals)) {
                        // Check original request for parameters pruned during parent validation
                        $originalValue = null;
                        if ($originalRequest) {
                            $query = $originalRequest->getQueryParams();
                            if (array_key_exists($k, $query)) {
                                $originalValue = $query[$k];
                            } else {
                                $body = $originalRequest->getParsedBody();
                                if (is_array($body) && array_key_exists($k, $body)) {
                                    $originalValue = $body[$k];
                                }
                            }
                        }
                        $originals[$k] = $originalValue;
                    }
                    $rdh = $rdh->setParameter($k, $v);
                }
                $this->controller->getContext()->setRequest($rdh);
                // (former temporary GuidanceSection instrumentation removed)
                $overlayApplied = true;
                if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
                    try {
                        $logger->debug('[SlotDisp] overlay_applied key=' . $key . ' params=' . json_encode($parameters, JSON_UNESCAPED_SLASHES));
                    } catch (\Throwable) {
                    }
                }
            }
            // Normalize output type to lowercase as configuration keys are lowercase
            $normalizedOutputType = $outputType !== null ? strtolower($outputType) : null;
            // Determine upfront which execution mode to use so we only create a legacy container if required.
            $actionInstance = $this->controller->createActionInstance($module, $action);
            // Hard break: container path removed. Always container-less execution.
            if (!$rdh) {
                try {
                    $rdh = $this->controller->getContext()->getRequest();
                } catch (\Throwable) {
                    $rdh = null;
                }
                if (!($rdh instanceof WebRequest)) {
                    throw new \RuntimeException('Canonical WebRequest missing in SlotDispatcher::dispatch (simple)');
                }
            }
            if ($cacheEnabled) {
                $normalizedOutputType = $outputType !== null ? strtolower($outputType) : $this->controller->getOutputType()->getName();
                // Tag/version support: actions may expose slotCacheTags(array $params): array
                $tags = [];
                if (method_exists($actionInstance, 'slotCacheTags')) { // dynamic optional
                    try {
                        $tags = (array)call_user_func([$actionInstance, 'slotCacheTags'], $parameters);
                    } catch (\Throwable) {
                        $tags = [];
                    }
                }
                $tagSuffix = '';
                if ($tags) {
                    $versions = [];
                    foreach ($tags as $t) {
                        $safe = preg_replace('/[^a-z0-9:_-]/i', '_', (string)$t);
                        try {
                            $versions[] = CacheManager::getNamespaceVersion('slot_tag:' . $safe);
                        } catch (\Throwable) {
                            $versions[] = '0';
                        }
                    }
                    $tagSuffix = ':' . implode('.', $versions);
                }
                $encodedParameters = json_encode($parameters);
                // json_encode() can fail (e.g. malformed UTF-8, resources) and return false;
                // hashing that verbatim would silently collapse every failing-encode call into
                // the same cache key. Fall back to a per-call unique key instead so we never
                // serve unrelated cached content when encoding fails.
                $parametersDigest = $encodedParameters !== false ? md5($encodedParameters) : ('uncacheable:' . bin2hex(random_bytes(8)));
                $cacheKey = 'slot:' . strtolower($module) . ':' . strtolower($action) . ':' . $normalizedOutputType . $tagSuffix . ':' . $parametersDigest;
                try {
                    $cached = CacheManager::getCache()->get($cacheKey);
                    if (is_string($cached)) {
                        $cacheHit = true;
                        return $cached;
                    }
                } catch (\Throwable) {
                }
            }
            if ($actionInstance->isSimple()) {
                // Mark action as slot for downstream views/layout selection (container-less compatibility)
                try {
                    $logger->debug('[SlotDispatcher] Setting is_slot=true on simple action ' . $actionInstance::class);
                    $actionInstance->setAttribute('is_slot', true);
                    $logger->debug('[SlotDispatcher] is_slot set, checking: ' . ($actionInstance->hasAttribute('is_slot') ? 'found' : 'not found'));
                } catch (\Throwable $e) {
                    $logger->debug('[SlotDispatcher] Failed to set is_slot attribute: ' . $e->getMessage());
                }
                // Early experimental path: execute simple action without full container
                $rd = $rdh;
                // Agavi heritage: isSimple() means "skip execute*() entirely,
                // render getDefaultViewName() directly" -- this was introduced
                // (commit f166330f4, 2007) specifically for slots, which don't
                // need a full round of validation/business logic just to
                // render a fragment. Do NOT call the resolver here.
                try {
                    $rawViewName = $actionInstance->getDefaultViewName();
                } catch (\Throwable $e) {
                    if ($logExceptions) {
                        $this->logSlotException($e, $module, $action, $parameters, 'simple_action_execute');
                    }
                    throw $e;
                }
                $attributeSnapshot = [];
                try {
                    $attributeSnapshot = self::normalizeAttributeKeys($actionInstance->getAttributes());
                } catch (\Throwable) {
                    $attributeSnapshot = [];
                }
                [$viewModule, $viewCanonical] = $this->viewNameResolver->resolve($module, $action, $rawViewName);
                $viewInstance = null;
                $result = '';
                if ($viewCanonical !== View::NONE && $viewModule !== null) {
                    try {
                        $viewInstance = $this->viewFactory->create($viewModule, $viewCanonical, $module, $action, strtolower(($outputType ?? $this->controller->getOutputType()->getName())), $rd, $attributeSnapshot);
                    } catch (\Throwable $e) {
                        if ($logExceptions) {
                            $this->logSlotException($e, $module, $action, $parameters, 'simple_view_factory_create');
                        }
                        throw $e;
                    }
                    if (!$viewInstance) {
                        try {
                            $viewInstance = $this->controller->createViewInstance($viewModule, $viewCanonical);
                        } catch (\Throwable) {
                        }
                        if ($viewInstance) {
                            try {
                                $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $viewModule, $viewCanonical, strtolower(($outputType ?? $this->controller->getOutputType()->getName())), $module, $action, (array)$attributeSnapshot, $this->controller->getGlobalResponse());
                                $viewInstance->initialize($vic);
                            } catch (\Throwable) {
                            }
                        }
                    }
                    $method = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName());
                    if (!$viewInstance || !is_callable([$viewInstance, $method])) {
                        $method = 'execute';
                    }
                    try {
                        $res = $viewInstance?->$method($rd);
                    } catch (\Throwable $e) {
                        if ($logExceptions) {
                            $this->logSlotException($e, $module, $action, $parameters, 'simple_view_execute');
                        }
                        throw $e;
                    }
                    if ($res !== null) {
                        $result = (string)$res;
                    } elseif ($viewInstance && $viewInstance->getLayers()) {
                        $layerContent = $viewInstance->renderLayers();
                        if ($layerContent !== '') {
                            $result = $layerContent;
                        }
                    }
                }
                if ($cacheEnabled) {
                    $ttl = null;
                    if (method_exists($actionInstance, 'slotCacheTtlSeconds')) {
                        try {
                            $ttl = (int)call_user_func([$actionInstance, 'slotCacheTtlSeconds']);
                        } catch (\Throwable) {
                            $ttl = null;
                        }
                    }
                    try {
                        CacheManager::getCache()->set($cacheKey, $result, $ttl ?: null);
                    } catch (\Throwable) {
                    }
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
                $rd = $rdh;
                // Initialize action with lightweight context (mirrors ActionExecutor)
                try {
                    $lwCtx = new LightweightActionInitContext(
                        $this->controller->getContext(),
                        $module,
                        $action,
                        strtoupper($parentRequest->getMethod()),
                        strtolower(($outputType ?? $this->controller->getOutputType()->getName())),
                        $rd,
                        $this->controller->getGlobalResponse()
                    );
                    $actionInstance->initialize($lwCtx);
                } catch (\Throwable $e) {
                    if ($logExceptions) {
                        $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_action_initialize');
                    }
                    throw $e;
                }

                // Mark action as slot AFTER initialization (when initContext exists)
                try {
                    $actionInstance->setAttribute('is_slot', true);
                } catch (\Throwable $e) {
                    $logger->debug('[SlotDispatcher] Failed to set is_slot attribute: ' . $e->getMessage());
                }
                $securityService = new SecurityService($this->controller);
                $decision = $securityService->decide($actionInstance);
                if ($decision !== SecurityDecision::Allow) {
                    // Security denied for slot execution. Rendering the full system
                    // forward (login/secure) would produce a full page layout which
                    // itself renders slots (including the current one) and can
                    // therefore cause unbounded recursion during slot dispatch.
                    // For slot dispatches we fail closed: return empty content and
                    // record a small diagnostic context so callers can inspect the
                    // lastContext if needed.
                    try {
                        $logger->debug(sprintf('[SlotDisp] security denied for slot %s/%s during slot dispatch - returning empty content', $module, $action));
                    } catch (\Throwable) {
                    }
                    $ctx = new ActionExecutionContext($actionInstance, null, $module, $action, $outputType ?? $this->controller->getOutputType()->getName(), $rd, '');
                    $this->lastContext = $ctx;
                    return $ctx->content;
                }
                // Validation
                $validationService = new ValidationService();
                try {
                        // Map HTTP verb to logical validation method token consistent with container path.
                        $httpVerb = strtoupper($parentRequest->getMethod());
                        $methodToken = match($httpVerb) {
                            'GET', 'HEAD', 'OPTIONS' => 'Read',
                            'POST' => 'Write',
                            'PUT' => 'Put',
                            'PATCH' => 'Patch',
                            'DELETE' => 'Delete',
                            default => 'Read',
                        };
                        // (former temporary GuidanceSection pre-validation instrumentation removed)
                        $vres = $validationService->validate($actionInstance, $rd, $module, $action, $methodToken);
                        // (former temporary GuidanceSection post-validation instrumentation removed)
                } catch (\Throwable $e) {
                    if ($logExceptions) {
                        $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_validation');
                    }
                    throw $e;
                }
                if (!$vres->ok) {
                    try {
                        $rawViewName = $actionInstance->handleError($rd);
                    } catch (\Throwable $e) {
                        if ($logExceptions) {
                            $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_handle_error');
                        }
                        throw $e;
                    }
                    [$vm, $vn] = $this->viewNameResolver->resolve($module, $action, $rawViewName);
                    $viewInstance = null;
                    $content = '';
                    if ($vn !== View::NONE && $vm !== null) {
                        try {
                            $viewInstance = $this->viewFactory->create($vm, $vn, $module, $action, strtolower(($outputType ?? $this->controller->getOutputType()->getName())), $rd, self::normalizeAttributeKeys($actionInstance->getAttributes()));
                        } catch (\Throwable $e) {
                            if ($logExceptions) {
                                $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_error_view_factory_create');
                            }
                            throw $e;
                        }
                        if (!$viewInstance) {
                            try {
                                $viewInstance = $this->controller->createViewInstance($vm, $vn);
                            } catch (\Throwable) {
                            }
                        }
                        if ($viewInstance) {
                            try {
                                $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $vm, $vn, strtolower(($outputType ?? $this->controller->getOutputType()->getName())), $module, $action, self::normalizeAttributeKeys($actionInstance->getAttributes()), $this->controller->getGlobalResponse());
                                $viewInstance->initialize($vic);
                            } catch (\Throwable) {
                            }
                        }
                        $methodExec = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName());
                        if (!$viewInstance || !is_callable([$viewInstance, $methodExec])) {
                            $methodExec = 'execute';
                        }
                        try {
                            $res = $viewInstance?->$methodExec($rd);
                        } catch (\Throwable $e) {
                            if ($logExceptions) {
                                $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_error_view_execute');
                            }
                            throw $e;
                        }
                        if ($res !== null) {
                            $content = (string)$res;
                        } elseif ($viewInstance && $viewInstance->getLayers()) {
                            $layerContent = $viewInstance->renderLayers();
                            if ($layerContent !== '') {
                                $content = $layerContent;
                            }
                        }
                    }
                    $ctx = new ActionExecutionContext($actionInstance, $viewInstance, $module, $action, $outputType ?? $this->controller->getOutputType()->getName(), $rd, (string)$content, $vm, $vn);
                    $this->lastContext = $ctx;
                    return $ctx->content;
                }
                // Execute action method
                $requestMethod = strtoupper($parentRequest->getMethod());
                try {
                    $rawViewName = $this->actionResolver->execute($actionInstance, $requestMethod, $rd);
                } catch (\Throwable $e) {
                    if ($logExceptions) {
                        $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_action_execute');
                    }
                    throw $e;
                }
                [$vm, $vn] = $this->viewNameResolver->resolve($module, $action, $rawViewName);
                $viewInstance = null;
                $result = '';
                if ($vn !== View::NONE && $vm !== null) {
                    $attrs = self::normalizeAttributeKeys($actionInstance->getAttributes());
                    try {
                        $viewInstance = $this->viewFactory->create($vm, $vn, $module, $action, strtolower(($outputType ?? $this->controller->getOutputType()->getName())), $rd, $attrs);
                    } catch (\Throwable $e) {
                        if ($logExceptions) {
                            $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_view_factory_create');
                        }
                        throw $e;
                    }
                    if (!$viewInstance) {
                        try {
                            $viewInstance = $this->controller->createViewInstance($vm, $vn);
                        } catch (\Throwable) {
                        }
                    }
                    if ($viewInstance) {
                        try {
                            $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $vm, $vn, strtolower(($outputType ?? $this->controller->getOutputType()->getName())), $module, $action, $attrs, $this->controller->getGlobalResponse());
                            $viewInstance->initialize($vic);
                        } catch (\Throwable) {
                        }
                    }
                    $methodExec = 'execute' . ($outputType ?? $this->controller->getOutputType()->getName());
                    if (!$viewInstance || !is_callable([$viewInstance, $methodExec])) {
                        $methodExec = 'execute';
                    }
                    try {
                        $res = $viewInstance?->$methodExec($rd);
                    } catch (\Throwable $e) {
                        if ($logExceptions) {
                            $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_view_execute');
                        }
                        throw $e;
                    }
                    if ($res !== null) {
                        $result = (string)$res;
                    } elseif ($viewInstance && $viewInstance->getLayers()) {
                        $layerContent = $viewInstance->renderLayers();
                        if ($layerContent !== '') {
                            $result = $layerContent;
                        }
                    }
                }
                if ($cacheEnabled) {
                    $ttl = null;
                    if (method_exists($actionInstance, 'slotCacheTtlSeconds')) {
                        try {
                            $ttl = (int)call_user_func([$actionInstance, 'slotCacheTtlSeconds']);
                        } catch (\Throwable) {
                            $ttl = null;
                        }
                    }
                    try {
                        CacheManager::getCache()->set($cacheKey, $result, $ttl ?: null);
                    } catch (\Throwable) {
                    }
                }
                $attrsFinal = self::normalizeAttributeKeys($actionInstance->getAttributes());
                $ctx = new ActionExecutionContext($actionInstance, $viewInstance, $module, $action, $outputType ?? $this->controller->getOutputType()->getName(), $rd, (string)$result, $vm, $vn, $attrsFinal);
                $this->lastContext = $ctx;
                return $ctx->content;
            }
        } finally {
            // Restore original parameters if overlay applied
            if (isset($overlayApplied) && $overlayApplied && isset($rdh) && isset($originals)) {
                foreach ($originals as $k => $v) {
                    if ($v === null) {
                        // Parameter didn't exist before overlay; remove if current matches overlay value.
                        // We can't know overlay value without storing; accept leaving value as is if mismatch risk.
                        // Safer: remove unconditionally when original null and key exists.
                        try {
                            $rdh = $rdh->removeParameter($k);
                        } catch (\Throwable) {
                        }
                    } else {
                        try {
                            $rdh = $rdh->setParameter($k, $v);
                        } catch (\Throwable) {
                        }
                    }
                }
                try {
                    $this->controller->getContext()->setRequest($rdh);
                } catch (\Throwable) {
                }
            }
            $this->executionGuard->leave($stack);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function logSlotException(\Throwable $e, string $module, string $action, array $parameters, string $phase): void
    {
        try {
            $payload = json_encode([
                'phase' => $phase,
                'module' => $module,
                'action' => $action,
                'parameters' => $parameters,
                'class' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $this->truncateTrace($e->getTraceAsString()),
                'time' => date('c'),
            ]);
            \error_log('SLOT_EXCEPTION ' . $payload);
        } catch (\Throwable) {
            // Never mask original exception
        }
    }

    private function truncateTrace(string $trace, int $max = 8000): string
    {
        if (strlen($trace) <= $max) {
            return $trace;
        }
        return substr($trace, 0, $max) . '... [truncated]';
    }

    /**
     * Experimental API: identical to dispatch() but returns ActionExecutionContext alongside content.
     * @param array<string, mixed> $parameters
     */
    public function dispatchWithContext(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): ActionExecutionContext
    {
        $content = $this->dispatch($parentRequest, $module, $action, $parameters, $outputType);
        if ($this->lastContext) {
            return $this->lastContext;
        }
        // Fallback: synthesize minimal context when container path used
        $sharedRequest = null;
        try {
            $sharedRequest = $this->controller->getContext()->getRequest();
        } catch (\Throwable) {
            $sharedRequest = null;
        }
        if (!($sharedRequest instanceof WebRequest)) {
            throw new \RuntimeException('Canonical WebRequest missing in SlotDispatcher::dispatchWithContext fallback');
        }
        return new ActionExecutionContext(
            action: $this->controller->createActionInstance($module, $action),
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
     * @param array<string, mixed> $parameters
     */
    public function dispatchSlotContent(ServerRequestInterface $parentRequest, string $module, string $action, array $parameters = [], ?string $outputType = null): SlotContent
    {
        $ctx = $this->dispatchWithContext($parentRequest, $module, $action, $parameters, $outputType);
        return new SlotContent($module, $action, $ctx->outputType, $ctx->content, $parameters);
    }

    /**
     * Experimental: dispatch slot and return SlotExecutionContext (immutable) for richer metadata.
     * @param array<string, mixed> $parameters
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
            actionAttributes: $ctx->actionAttributes,
            parameters: $parameters
        );
    }
}
