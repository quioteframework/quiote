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
use Quiote\Execution\ViewNameResolver;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Execution\ActionResolver;
use Quiote\Action\Action;
use Quiote\Execution\SlotContent;
use Quiote\Cache\CacheManager;
use Quiote\Config\Config;
use Quiote\Execution\Slot\SlotCache;
use Quiote\Execution\Slot\SlotParameterOverlay;
use Quiote\Request\WebRequest;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Environment\EnvironmentReaderInterface;
use Quiote\Support\Environment\SystemEnvironmentReader;

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
    private readonly ClockInterface $clock;
    private readonly EnvironmentReaderInterface $environment;

    // Retained only to keep the constructor signature stable for callers that
    // inject it; nothing in this class reads it back yet.
    private ?ForwardService $forwardService = null;
    // Reused across slot dispatches: SecurityService is stateless (only a readonly
    // Controller reference), so a fresh instance per dispatch was pure allocation.
    private ?SecurityService $securityService = null;

    public function __construct(private readonly Controller $controller, ?ActionResolver $actionResolver = null, ?SlotExecutionGuard $executionGuard = null, ?ViewNameResolver $viewNameResolver = null, ?ForwardService $forwardService = null, ?ViewFactory $viewFactory = null, ?ClockInterface $clock = null, ?EnvironmentReaderInterface $environment = null)
    {
        // Initialize pure resolver
        $this->viewNameResolver = $viewNameResolver ?? new ViewNameResolver();
        $this->actionResolver = $actionResolver ?? new ActionResolver();
        $this->executionGuard = $executionGuard ?? new SlotExecutionGuard(self::RECURSION_LIMIT);
        $this->forwardService ??= $forwardService ?? new ForwardService($controller);
        $this->viewFactory = $viewFactory ?? new ViewFactory($controller);
        $this->clock = $clock ?? new SystemClock();
        $this->environment = $environment ?? new SystemEnvironmentReader();
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
        $logger->debugWith(
            fn(): string => sprintf(
                '[SlotDisp] dispatch parentRequest id=%d slotstack=%s key=%s',
                spl_object_id($parentRequest),
                $stack ? '1' : '0',
                $key
            )
        );
        if (!$stack) {
            throw new QuioteException('SlotStack missing from request; ensure SlotMiddleware is registered.');
        }
        // Soft-guard: if the next push would exceed the configured limit, fail soft
        // to prevent runaway rendering loops; emit a single log per key per request.
        try {
            if ($this->executionGuard->wouldExceed($stack, $key)) {
                if (!$stack->hasWarned($key)) {
                    $stack->markWarned($key);
                    $logger->debugWith(
                        fn(): string => sprintf(
                            '[SlotDisp] recursion guard triggered for key=%s parentRequest id=%d',
                            $key,
                            spl_object_id($parentRequest)
                        )
                    );
                }
                // Fail closed: return empty content instead of throwing to keep rendering going.
                return '';
            }
        } catch (\Throwable $e) {
            // enter() still enforces the hard limit below, so rendering continues -- but a
            // guard that cannot evaluate its own soft limit is worth knowing about.
            $logger->warning(
                '[SlotDisp] recursion soft-guard check failed for key=' . $key
                . '; relying on the hard limit: ' . $e->getMessage()
            );
        }
        // Built before the try: the finally block restores through the overlay, so it has to
        // exist even if the body throws on its first statement.
        $overlay = new SlotParameterOverlay($this->controller->getContext(), $logger, $key);
        $slotCache = new SlotCache($logger, $key);

        $this->executionGuard->enter($stack, $key);
        try {
            // Resolve the effective output type once for the whole dispatch; the raw
            // and lowercased forms are referenced many times below (view creation,
            // init contexts, execute-method name, execution context) and previously
            // re-ran getOutputType()->getName() + strtolower() at each site.
            $resolvedOutputType = $outputType ?? $this->controller->getOutputType()->getName();
            $resolvedOutputTypeLower = strtolower($resolvedOutputType);
            $cacheEnabled = Config::getBool('core.use_cache', false) && (bool)$this->environment->get('QUIOTE_SLOT_CACHE');
            $cacheKey = null;
            $cacheHit = false;
            // Slot parameters go onto the shared request for the length of this dispatch only.
            $rdh = null;
            if ($parameters) {
                $rdh = $overlay->apply($parameters);
            }
            // Normalize output type to lowercase as configuration keys are lowercase
            $normalizedOutputType = $outputType !== null ? strtolower($outputType) : null;
            // Determine upfront which execution mode to use so we only create a legacy container if required.
            $actionInstance = $this->controller->createActionInstance($module, $action);
            // Hard break: container path removed. Always container-less execution.
            if (!$rdh) {
                try {
                    $rdh = $this->controller->getContext()->getContainer()->get(\Quiote\Request\WebRequest::class);
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
                $cacheKey = $slotCache->keyFor($module, $action, (string)$normalizedOutputType, $parameters, $tags);
                $decoded = $slotCache->read($cacheKey);
                if ($decoded !== null) {
                    $cacheHit = true;
                    return $decoded;
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
                        $viewInstance = $this->viewFactory->create($viewModule, $viewCanonical, $module, $action, $resolvedOutputTypeLower, $rd, $attributeSnapshot);
                    } catch (\Throwable $e) {
                        if ($logExceptions) {
                            $this->logSlotException($e, $module, $action, $parameters, 'simple_view_factory_create');
                        }
                        throw $e;
                    }
                    if (!$viewInstance) {
                        try {
                            $viewInstance = $this->controller->createViewInstance($viewModule, $viewCanonical);
                        } catch (\Throwable $e) {
                            // Left null; the caller falls through to rendering without a view.
                            $logger->warning(
                                '[SlotDisp] could not create view ' . $viewModule . ':' . $viewCanonical
                                . ' for slot ' . $key . ': ' . $e->getMessage()
                            );
                        }
                        if ($viewInstance) {
                            try {
                                $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $viewModule, $viewCanonical, $resolvedOutputTypeLower, $module, $action, (array)$attributeSnapshot, $this->controller->getGlobalResponse());
                                $viewInstance->initialize($vic);
                            } catch (\Throwable $e) {
                                // An uninitialized view renders without its init context rather
                                // than aborting the whole page.
                                $logger->warning(
                                    '[SlotDisp] could not initialize the view for slot ' . $key . ': '
                                    . $e->getMessage()
                                );
                            }
                        }
                    }
                    $method = 'execute' . ($resolvedOutputType);
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
                        $slotCache->write($cacheKey, $result, $ttl);
                    } catch (\Throwable $e) {
                        // The rendered output is already correct; only the caching of it failed.
                        $logger->warning(
                            '[SlotDisp] slot cache write failed for key=' . $key . ': ' . $e->getMessage()
                        );
                    }
                }
                // Build execution context (presently unused by caller, but enables future hooks)
                // Build context but only return content (use dispatchWithContext() for caller access)
                $viewOutputType = $resolvedOutputType;
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
                        $resolvedOutputTypeLower,
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
                $securityService = $this->securityService ??= new SecurityService($this->controller);
                $decision = $securityService->decide($actionInstance);
                if ($decision !== SecurityDecision::Allow) {
                    // Security denied for slot execution. Rendering the full system
                    // forward (login/secure) would produce a full page layout which
                    // itself renders slots (including the current one) and can
                    // therefore cause unbounded recursion during slot dispatch.
                    // For slot dispatches we fail closed: return empty content and
                    // record a small diagnostic context so callers can inspect the
                    // lastContext if needed.
                    $logger->debugWith(
                        fn(): string => sprintf(
                            '[SlotDisp] security denied for slot %s/%s during slot dispatch - returning empty content',
                            $module,
                            $action
                        )
                    );
                    $ctx = new ActionExecutionContext($actionInstance, null, $module, $action, $resolvedOutputType, $rd, '');
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
                // WebRequest is immutable: a validator's export() (e.g. <ae:parameter name="export">)
                // only replaces the container's published instance, not this local $rd captured before
                // validation ran -- mirrors the re-fetch ActionExecutor performs after action execution.
                // Re-read here so both the error handler below and the action execution further down see
                // whatever validation exported, instead of a stale $rd missing every exported parameter.
                try {
                    $rd = $this->controller->getContext()->getContainer()->get(WebRequest::class);
                } catch (\Throwable $e) {
                    // Keeps the pre-validation $rd, so an export a validator made may not be visible
                    // to the action or its error handler.
                    $logger->warning(
                        '[SlotDisp] could not re-read the request after validating slot ' . $key . '; '
                        . 'the action may not see its exports: ' . $e->getMessage()
                    );
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
                            $viewInstance = $this->viewFactory->create($vm, $vn, $module, $action, $resolvedOutputTypeLower, $rd, self::normalizeAttributeKeys($actionInstance->getAttributes()), $validationService->getValidationManager());
                        } catch (\Throwable $e) {
                            if ($logExceptions) {
                                $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_error_view_factory_create');
                            }
                            throw $e;
                        }
                        if (!$viewInstance) {
                            try {
                                $viewInstance = $this->controller->createViewInstance($vm, $vn);
                            } catch (\Throwable $e) {
                                // Left null; the caller falls through to rendering without a view.
                                $logger->warning(
                                    '[SlotDisp] could not create view ' . $vm . ':' . $vn
                                    . ' for slot ' . $key . ': ' . $e->getMessage()
                                );
                            }
                            if ($viewInstance) {
                                try {
                                    $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $vm, $vn, $resolvedOutputTypeLower, $module, $action, self::normalizeAttributeKeys($actionInstance->getAttributes()), $this->controller->getGlobalResponse(), validationManager: $validationService->getValidationManager());
                                    $viewInstance->initialize($vic);
                                } catch (\Throwable $e) {
                                    // An uninitialized view renders without its init context rather
                                    // than aborting the whole page.
                                    $logger->warning(
                                        '[SlotDisp] could not initialize the view for slot ' . $key . ': '
                                        . $e->getMessage()
                                    );
                                }
                            }
                        }
                        $methodExec = 'execute' . ($resolvedOutputType);
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
                    $ctx = new ActionExecutionContext($actionInstance, $viewInstance, $module, $action, $resolvedOutputType, $rd, (string)$content, $vm, $vn);
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
                        $viewInstance = $this->viewFactory->create($vm, $vn, $module, $action, $resolvedOutputTypeLower, $rd, $attrs, $validationService->getValidationManager());
                    } catch (\Throwable $e) {
                        if ($logExceptions) {
                            $this->logSlotException($e, $module, $action, $parameters, 'nonsimple_view_factory_create');
                        }
                        throw $e;
                    }
                    if (!$viewInstance) {
                        try {
                            $viewInstance = $this->controller->createViewInstance($vm, $vn);
                        } catch (\Throwable $e) {
                            // Left null; the caller falls through to rendering without a view.
                            $logger->warning(
                                '[SlotDisp] could not create view ' . $vm . ':' . $vn
                                . ' for slot ' . $key . ': ' . $e->getMessage()
                            );
                        }
                        if ($viewInstance) {
                            try {
                                $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $vm, $vn, $resolvedOutputTypeLower, $module, $action, $attrs, $this->controller->getGlobalResponse(), validationManager: $validationService->getValidationManager());
                                $viewInstance->initialize($vic);
                            } catch (\Throwable $e) {
                                // An uninitialized view renders without its init context rather
                                // than aborting the whole page.
                                $logger->warning(
                                    '[SlotDisp] could not initialize the view for slot ' . $key . ': '
                                    . $e->getMessage()
                                );
                            }
                        }
                    }
                    $methodExec = 'execute' . ($resolvedOutputType);
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
                        $slotCache->write($cacheKey, $result, $ttl);
                    } catch (\Throwable $e) {
                        // The rendered output is already correct; only the caching of it failed.
                        $logger->warning(
                            '[SlotDisp] slot cache write failed for key=' . $key . ': ' . $e->getMessage()
                        );
                    }
                }
                $attrsFinal = self::normalizeAttributeKeys($actionInstance->getAttributes());
                $ctx = new ActionExecutionContext($actionInstance, $viewInstance, $module, $action, $resolvedOutputType, $rd, (string)$result, $vm, $vn, $attrsFinal);
                $this->lastContext = $ctx;
                return $ctx->content;
            }
        } finally {
            $overlay->restore($rdh ?? null);
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
                'time' => $this->clock->now()->format('c'),
            ]);
            \error_log('SLOT_EXCEPTION ' . $payload);
        } catch (\Throwable $dumpFailure) {
            // This is the exception reporter itself, so there is nowhere better to escalate to:
            // the original throwable is what matters and must not be displaced by a failure to
            // describe it. Recorded without the payload it could not build.
            \error_log(
                'SLOT_EXCEPTION could not be serialized for ' . $module . '/' . $action . ' in phase '
                . $phase . ': ' . $dumpFailure->getMessage() . ' (original: ' . $e::class . ': '
                . $e->getMessage() . ')'
            );
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
            $sharedRequest = $this->controller->getContext()->getContainer()->get(\Quiote\Request\WebRequest::class);
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
