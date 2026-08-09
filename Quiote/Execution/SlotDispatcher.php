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

    /**
     * Marker prefixed to slot cache payloads that carry an explicit TTL (via
     * slotCacheTtlSeconds()). The backend (Symfony's FilesystemAdapter/ApcuAdapter)
     * computes its own expiry from two independent wall-clock time() reads
     * (write time + lifetime, compared against read time) — non-monotonic, so a
     * backward wall-clock step (observed on this host under load) can make an
     * actually-expired entry still read back as "fresh". For entries with an
     * explicit TTL we additionally stamp a monotonic (hrtime) expiry and honor
     * that instead, so slot cache freshness never depends on the wall clock.
     */
    private const MONO_TTL_MARKER = "\x00SCTTL1\x00";

    private ?ActionExecutionContext $lastContext = null;

    private readonly ActionResolver $actionResolver;
    private readonly SlotExecutionGuard $executionGuard;
    private readonly ViewNameResolver $viewNameResolver;
    private readonly ViewFactory $viewFactory;

    // Retained only to keep the constructor signature stable for callers that
    // inject it; nothing in this class reads it back yet.
    private ?ForwardService $forwardService = null;
    // Reused across slot dispatches: SecurityService is stateless (only a readonly
    // Controller reference), so a fresh instance per dispatch was pure allocation.
    private ?SecurityService $securityService = null;

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
        $this->executionGuard->enter($stack, $key);
        try {
            $start = microtime(true);
            // Resolve the effective output type once for the whole dispatch; the raw
            // and lowercased forms are referenced many times below (view creation,
            // init contexts, execute-method name, execution context) and previously
            // re-ran getOutputType()->getName() + strtolower() at each site.
            $resolvedOutputType = $outputType ?? $this->controller->getOutputType()->getName();
            $resolvedOutputTypeLower = strtolower($resolvedOutputType);
            $cacheEnabled = Config::getBool('core.use_cache', false) && (bool)getenv('QUIOTE_SLOT_CACHE');
            $cacheKey = null;
            $cacheHit = false;
            // Build request data holder: apply slot parameters via overlay (save originals, restore after dispatch).
            $rdh = null;
            $overlayApplied = false;
            $originals = [];
            if ($parameters) {
                try {
                    $rdh = $this->controller->getContext()->getContainer()->get(\Quiote\Request\WebRequest::class);
                } catch (\Throwable) {
                    $rdh = null;
                }
                if (!($rdh instanceof WebRequest)) {
                    throw new \RuntimeException('Canonical WebRequest missing when applying slot parameters');
                }
                foreach ($parameters as $k => $v) {
                    if (!array_key_exists($k, $originals)) {
                        // Snapshot what the parent request exposes for this name right now, so the
                        // overlay can be undone exactly. This reads the validated request, not the
                        // one the client sent: a parameter that validation pruned must stay pruned,
                        // because restoring the submitted value here would publish unvalidated
                        // input to everything rendered after this slot.
                        $present = $rdh->hasParameter($k);
                        $originals[$k] = [
                            'present' => $present,
                            'value' => $present ? $rdh->getParameter($k, null) : null,
                        ];
                    }
                    $rdh = $rdh->setParameter($k, $v);
                }
                $this->controller->getContext()->getContainer()->get(\Quiote\Request\RequestState::class)->publish($rdh);
                // (former temporary GuidanceSection instrumentation removed)
                $overlayApplied = true;
                if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
                    $logger->debugWith(
                        fn(): string => '[SlotDisp] overlay_applied key=' . $key
                            . ' params=' . json_encode($parameters, JSON_UNESCAPED_SLASHES)
                    );
                }
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
                // Cache keys are composed through CacheManager::key() rather than
                // concatenated with ':' — PSR-16 reserves that character.
                $keyParts = ['slot', strtolower($module), strtolower($action), $normalizedOutputType];
                if ($tags) {
                    $versions = [];
                    foreach ($tags as $t) {
                        try {
                            $versions[] = (string)CacheManager::getNamespaceVersion(CacheManager::slotTagNamespace((string)$t));
                        } catch (\Throwable) {
                            $versions[] = '0';
                        }
                    }
                    $keyParts[] = implode('-', $versions);
                }
                $encodedParameters = json_encode($parameters);
                // json_encode() can fail (e.g. malformed UTF-8, resources) and return false;
                // hashing that verbatim would silently collapse every failing-encode call into
                // the same cache key. Fall back to a per-call unique key instead so we never
                // serve unrelated cached content when encoding fails.
                $parametersDigest = $encodedParameters !== false ? md5($encodedParameters) : ('uncacheable-' . bin2hex(random_bytes(8)));
                $keyParts[] = $parametersDigest;
                $cacheKey = CacheManager::key(...$keyParts);
                try {
                    $cached = CacheManager::getCache()->get($cacheKey);
                    $decoded = $this->decodeSlotCachePayload($cached);
                    if ($decoded !== null) {
                        $cacheHit = true;
                        return $decoded;
                    }
                } catch (\Throwable $e) {
                    // Treated as a miss: the slot renders below, so the page is correct and
                    // only slower. Reported because a cache that cannot be read at all is a
                    // silent performance cliff.
                    $logger->warning(
                        '[SlotDisp] slot cache read failed for key=' . $key . '; rendering uncached: '
                        . $e->getMessage()
                    );
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
                        CacheManager::getCache()->set($cacheKey, $this->encodeSlotCachePayload($result, $ttl), $ttl ?: null);
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
                            $viewInstance = $this->viewFactory->create($vm, $vn, $module, $action, $resolvedOutputTypeLower, $rd, self::normalizeAttributeKeys($actionInstance->getAttributes()));
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
                        }
                        if ($viewInstance) {
                            try {
                                $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $vm, $vn, $resolvedOutputTypeLower, $module, $action, self::normalizeAttributeKeys($actionInstance->getAttributes()), $this->controller->getGlobalResponse());
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
                        $viewInstance = $this->viewFactory->create($vm, $vn, $module, $action, $resolvedOutputTypeLower, $rd, $attrs);
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
                    }
                    if ($viewInstance) {
                        try {
                            $vic = new \Quiote\Execution\ImmutableViewInitContext($this->controller->getContext(), $vm, $vn, $resolvedOutputTypeLower, $module, $action, $attrs, $this->controller->getGlobalResponse());
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
                        CacheManager::getCache()->set($cacheKey, $this->encodeSlotCachePayload($result, $ttl), $ttl ?: null);
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
            // Restore original parameters if overlay applied
            if (isset($overlayApplied) && $overlayApplied && isset($rdh) && isset($originals)) {
                foreach ($originals as $k => $original) {
                    if (!$original['present']) {
                        // The parent request did not expose this name before the overlay, so
                        // neither the slot's value nor the whitelist entry setParameter() added
                        // for it may survive: leaving the name declared would turn a later
                        // getParameter() from a refusal into a silent null.
                        try {
                            $rdh = $rdh->revokeParameter((string)$k);
                        } catch (\Throwable $e) {
                            // A parameter the overlay introduced is still on the request, so the
                            // rest of the page can read a value that belonged to this slot alone.
                            $logger->error(
                                '[SlotDisp] could not remove overlay parameter "' . $k . '" after slot '
                                . $key . '; it remains visible to the parent request: ' . $e->getMessage()
                            );
                        }
                    } else {
                        try {
                            $rdh = $rdh->setParameter((string)$k, $original['value']);
                        } catch (\Throwable $e) {
                            // The parent's original value could not be put back, so the slot's
                            // value stands in for it for the rest of the render.
                            $logger->error(
                                '[SlotDisp] could not restore parameter "' . $k . '" after slot ' . $key
                                . '; the parent request keeps the slot value: ' . $e->getMessage()
                            );
                        }
                    }
                }
                try {
                    $this->controller->getContext()->getContainer()->get(\Quiote\Request\RequestState::class)->publish($rdh);
                } catch (\Throwable $e) {
                    // The restored request never reached the context, so everything after this
                    // slot reads the overlaid one.
                    $logger->error(
                        '[SlotDisp] could not publish the restored request after slot ' . $key
                        . '; the parent request keeps the slot overlay: ' . $e->getMessage()
                    );
                }
            }
            $this->executionGuard->leave($stack);
        }
    }

    /**
     * Encode a slot cache payload. When an explicit positive TTL is given, the
     * content is wrapped with a monotonic (hrtime) expiry stamp so freshness
     * can be verified independently of the cache backend's wall-clock-based
     * expiry (see MONO_TTL_MARKER doc comment). Without an explicit TTL, the
     * raw content is stored as before and the backend's own default expiry
     * applies untouched.
     */
    private function encodeSlotCachePayload(string $result, ?int $ttl): string
    {
        if ($ttl === null || $ttl <= 0) {
            return $result;
        }
        $encoded = json_encode(['c' => $result, 'e' => hrtime(true) + ($ttl * 1_000_000_000)]);
        if ($encoded === false) {
            // Content isn't representable as JSON (e.g. invalid UTF-8); fall back
            // to storing it raw rather than dropping the cache entry entirely.
            return $result;
        }
        return self::MONO_TTL_MARKER . $encoded;
    }

    /**
     * Decode a slot cache payload previously written by encodeSlotCachePayload().
     * Returns the cached content on a genuine hit, or null on a miss/expiry so
     * the caller re-executes the action. Plain (unwrapped) strings are always
     * treated as hits, matching pre-existing behavior for TTL-less entries.
     */
    private function decodeSlotCachePayload(mixed $cached): ?string
    {
        if (!is_string($cached)) {
            return null;
        }
        if (!str_starts_with($cached, self::MONO_TTL_MARKER)) {
            return $cached;
        }
        $decoded = json_decode(substr($cached, strlen(self::MONO_TTL_MARKER)), true);
        if (!is_array($decoded) || !isset($decoded['c'], $decoded['e']) || !is_string($decoded['c']) || !is_int($decoded['e'])) {
            return null;
        }
        if (hrtime(true) > $decoded['e']) {
            // Monotonically expired, even if the backend's own wall-clock check
            // would have still called it fresh.
            return null;
        }
        return $decoded['c'];
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
