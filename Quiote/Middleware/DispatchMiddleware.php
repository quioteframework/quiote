<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\Controller;
// Removed legacy container & request adapter usage.
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\SecurityDecision;
use Quiote\Execution\ActionExecutor; // new container-less executor
use Quiote\Execution\ActionExecutionSession; // transitional session abstraction
use Quiote\Execution\LightweightActionInitContext; // lightweight init context for action/view
use Quiote\View\View;
use Quiote\Util\Toolkit;
use Quiote\Config\Config;
use Quiote\Cache\CacheManager;
use Quiote\Cache\ActionViewCache;
use Quiote\Execution\ActionCacheHelper;
use Quiote\Exception\QuioteException;
use Quiote\Execution\ValidationDecision;

/**
 * DispatchMiddleware runs the requested action. Simple and non-simple actions
 * alike go through ActionExecutor, and caching operates on executor output.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'action')]
class DispatchMiddleware implements MiddlewareInterface
{
    use RequestDiagnostics;

    private readonly ActionExecutor $actionExecutor;
    /** @var array<string, bool> */
    private static array $executedNonSimpleActions = [];

    /**
     * Per-worker cache: class name → result of dynamicFlagsActive property scan.
     * Reflection is expensive; scan each class once and cache the boolean.
     * @var array<string,bool>
     */
    private static array $dynamicFlagsCache = [];

    // Forward loop protection moved to SecurityMiddleware (forwardCount in ExecutionState)

    public function __construct(private readonly Controller $controller)
    {
        // Always use ActionExecutor; legacy container path removed unconditionally.
        $this->actionExecutor = new ActionExecutor($controller);
    }

    /**
     * The cache partition for this action's output, as a three-way answer:
     *
     *  - null   -- no partitioning needed; one entry is shared by every caller.
     *  - string -- the partition key; only callers resolving to the same key
     *              may see each other's cached output.
     *  - false  -- the identity this output belongs to could not be established;
     *              the caller must neither read nor write the cache.
     *
     * A secure action renders for one specific identity, so its entry has to be
     * bound to that identity. This used to reduce to sha1('auth:1') for every
     * authenticated user -- one shared entry holding whichever user rendered
     * first, served to all the others.
     *
     * The binding is the session id, hashed. It is the only identity handle the
     * framework guarantees: {@see \Quiote\User\ISecurityUser} exposes no user id,
     * and roles/credentials do not identify a user (two users with identical
     * roles still see different content). Partitioning per session rather than
     * per user costs a duplicate entry for a user with two sessions, which is the
     * harmless direction.
     *
     * @param      object|null $actionInstance The action whose output would be cached.
     * @param      ?string $outputType The output type being rendered, or null.
     * @return     string|false|null The partition key, false to skip caching, or null for a shared entry.
     */
    private function computeUserFingerprint($actionInstance, ?string $outputType = null): string|false|null
    {
        try {
            if (!$actionInstance || !method_exists($actionInstance, 'isSecure') || !$actionInstance->isSecure()) {
                return null;
            }
            if (
                method_exists($actionInstance, 'cacheVaryByUser')
                && !$actionInstance->cacheVaryByUser($outputType)
            ) {
                // The action asserts its output does not depend on which user is
                // looking at it. See Action::cacheVaryByUser().
                return null;
            }
            // '' is what NullSessionBag answers, i.e. "no session installed".
            $sessionId = $this->controller->getContext()->getContainer()->get(\Quiote\Session\SessionBagInterface::class)->getId();
            if ($sessionId === '') {
                // Fail closed: no identity handle means no way to keep this
                // user's output out of another user's response.
                return false;
            }
            return hash('sha256', 'quiote.avcache.user' . "\0" . $sessionId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param object|null $actionInstance
     */
    private function dynamicFlagsActive($actionInstance): bool
    {
        if (!$actionInstance) {
            return false;
        }
        $cls = $actionInstance::class;
        if (array_key_exists($cls, self::$dynamicFlagsCache)) {
            return self::$dynamicFlagsCache[$cls];
        }
        try {
            foreach (['failValidation', 'requireAuth', 'requireCred'] as $p) {
                if (property_exists($cls, $p)) {
                    $rp = new \ReflectionProperty($cls, $p);
                    if ($rp->isStatic() && $rp->getValue() === true) {
                        return self::$dynamicFlagsCache[$cls] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Cached as "no dynamic flags", which is the ordinary answer for a class that
            // declares none.
            \Quiote\Logging\Log::for($this)->debug(
                '[DispatchMiddleware] could not scan ' . $cls . ' for dynamic test flags: '
                . $e->getMessage()
            );
        }
        return self::$dynamicFlagsCache[$cls] = false;
    }

    /**
     * The response an action staged via WebResponse::send(), or null if it never
     * called it. Duck-typed like the rest of this method's global-response access,
     * so a custom response class that doesn't implement the staging API simply
     * falls through to the rebuild path.
     */
    private function stagedResponseFrom(object $globalResp): ?ResponseInterface
    {
        if (!method_exists($globalResp, 'hasStagedResponse') || !method_exists($globalResp, 'getStagedResponse')) {
            return null;
        }
        try {
            if ($globalResp->hasStagedResponse() !== true) {
                return null;
            }
            $staged = $globalResp->getStagedResponse();
        } catch (\Throwable) {
            return null;
        }

        return $staged instanceof ResponseInterface ? $staged : null;
    }

    /**
     * @param array<string, mixed>|null $redirectSnapshot
     */
    private function buildPsrResponse(string $content, string $outputType, bool $cacheHit, bool $containerUsed, ?array $redirectSnapshot = null): ResponseInterface
    {

        $factory = \Quiote\Http\Psr17::factory();
        $status = 200;

        // TODO: propagate status from global response once unified interface available
        $resp = $factory->createResponse($status)->withBody($factory->createStream($content));

        // Set Content-Type and other headers from the OutputType configuration
        try {
            $ot = $this->controller->getOutputType($outputType);
            $httpHeaders = $ot->getParameter('http_headers', []);
            if (is_array($httpHeaders)) {
                foreach ($httpHeaders as $name => $value) {
                    // http_headers comes from untyped output-type config; only
                    // shapes withHeader() accepts are passed through.
                    if (is_string($value)) {
                        $resp = $resp->withHeader((string)$name, $value);
                    } elseif (is_array($value)) {
                        $lines = array_values(array_filter($value, 'is_string'));
                        if ($lines !== []) {
                            $resp = $resp->withHeader((string)$name, $lines);
                        }
                    }
                }
            }
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware.buildPsrResponse] set headers from output type ' . $outputType . ': ' . json_encode($httpHeaders));
            }
        } catch (\Throwable $e) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware.buildPsrResponse] exception getting output type: ' . $e->getMessage());
            }
        }

        $globalResp = null;
        try {
            $globalResp = $this->controller->getGlobalResponse();
        } catch (\Throwable $e) {
            // Without it there is no status override, no headers and no cookies to carry
            // over -- the body still goes out, but say what was lost.
            \Quiote\Logging\Log::for($this)->warning(
                '[DispatchMiddleware] global response unavailable; status, headers and cookies '
                . 'set on it will not reach this response: ' . $e->getMessage()
            );
        }
        if (is_object($globalResp)) {
            // An action that called WebResponse::send() has already materialized the
            // exact response it wants -- status, headers, cookies and body. Prefer it
            // wholesale rather than rebuilding a near-copy: send() no longer performs
            // transport of its own (that belongs to the runtime's emitter), so this is
            // the point at which what it staged rejoins the pipeline.
            $staged = $this->stagedResponseFrom($globalResp);
            if ($staged !== null) {
                return $staged;
            }
            try {
                $statusCode = (int)$globalResp->getHttpStatusCode();
                if ($statusCode >= 100) {
                    $resp = $resp->withStatus($statusCode);
                }
            } catch (\Throwable $e) {
                \Quiote\Logging\Log::for($this)->warning(
                    '[DispatchMiddleware] could not apply the status set on the global response; '
                    . 'serving ' . $resp->getStatusCode() . ': ' . $e->getMessage()
                );
            }
            try {
                foreach ((array)$globalResp->getHttpHeaders() as $name => $value) {
                    $resp = $resp->withHeader($name, $value);
                }
            } catch (\Throwable $e) {
                \Quiote\Logging\Log::for($this)->warning(
                    '[DispatchMiddleware] could not carry the global response headers over: ' . $e->getMessage()
                );
            }
        }

        $disableHeaders = Config::getBool('core.disable-framework-headers', false);
        if (!$disableHeaders) {
            $cacheHitHeader = Config::getString('core.cache-hit-header', 'X-Quiote-Cache-Hit');
            if ($cacheHit && $cacheHitHeader) {
                $resp = $resp->withHeader($cacheHitHeader, '1');
            }
            // Send X-Content-Type-Options: nosniff by default so browsers honor the
            // declared Content-Type and don't MIME-sniff responses into executable
            // types (an XSS/defense-in-depth measure). Only set it when the app/output
            // type hasn't already specified it, and allow opt-out via config.
            // NOTE: deliberately NOT setting X-Frame-Options — framing by external
            // sites must remain allowed.
            if (Config::getBool('core.send-nosniff-header', true) && !$resp->hasHeader('X-Content-Type-Options')) {
                $resp = $resp->withHeader('X-Content-Type-Options', 'nosniff');
            }
        }

        // Bridge redirect set via WebResponse::setRedirect() into the PSR response.
        // We use the snapshot captured in ActionExecutor immediately after the view ran,
        // avoiding a fiber/concurrency race where another request's clear() on the shared
        // global response would wipe the redirect before we read it here.
        $redirectData = $redirectSnapshot;
        if ($redirectData === null && is_object($globalResp)) {
            // Fallback: covers cache-hit and simple paths where no per-execution snapshot exists.
            try {
                $redirectData = $globalResp->getRedirect();
            } catch (\Throwable $e) {
                // A dropped redirect renders the page instead of navigating away, which is a
                // visible behaviour change rather than a cosmetic one.
                \Quiote\Logging\Log::for($this)->warning(
                    '[DispatchMiddleware] could not read the queued redirect; the response will not '
                    . 'redirect: ' . $e->getMessage()
                );
            }
        }
        if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
            \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware.buildPsrResponse] isRedirect=' . ($redirectData !== null ? 'true' : 'false'));
        }
        if ($redirectData !== null) {
            try {
                $locationRaw = $redirectData['location'] ?? '';
                $location = is_scalar($locationRaw) ? (string)$locationRaw : '';
                $codeRaw = $redirectData['code'] ?? 302;
                $code = is_numeric($codeRaw) ? (int)$codeRaw : 302;
                // Resolve relative URLs the same way WebResponse::send() does
                if (!preg_match('#^[^:]+://#', $location)) {
                    $quioteCtx = $this->controller->getContext();
                    if (isset($location[0]) && $location[0] === '/') {
                        $rq = $quioteCtx->getRequest();
                        $location = $rq->getUrlScheme() . '://' . $rq->getUrlAuthority() . $location;
                    } else {
                        $location = $quioteCtx->getContainer()->get(\Quiote\Routing\Routing::class)->getBaseHref() . $location;
                    }
                }
                if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                    \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware.buildPsrResponse] redirect location=' . $location . ' code=' . $code);
                }
                $resp = $resp->withStatus($code)->withHeader('Location', $location);
            } catch (\Throwable $e) {
                if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                    \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware.buildPsrResponse] redirect exception: ' . $e->getMessage());
                }
            }
        }

        // Bridge any cookies scheduled on the legacy Quiote WebResponse into PSR response headers
        if (is_object($globalResp)) {
            try {
                $routing = $this->controller->getContext()->getContainer()->get(\Quiote\Routing\Routing::class);
                $basePath = $routing->getBasePath();
                $resp = \Quiote\Http\CookieSerializer::bridge($globalResp, $resp, $basePath);
            } catch (\Throwable $e) {
                // Losing a Set-Cookie silently costs the client its session or CSRF token, so
                // this is reported even though the response itself is still serviceable.
                \Quiote\Logging\Log::for($this)->error(
                    '[DispatchMiddleware] cookies queued on the global response could not be '
                    . 'bridged onto the PSR response: ' . $e->getMessage()
                );
            }
        }
        return $resp;
    }

    /**
     * Short-circuits the normal Action/View/cache pipeline for actions that
     * stream Server-Sent Events -- there is nothing here to cache, validate,
     * or bridge redirects/cookies for; the action produces its own event
     * iterable directly.
     */
    private function buildSseResponse(\Quiote\Http\Sse\SseStreamingAction $action, ServerRequestInterface $request): ResponseInterface
    {
        $webRequest = ActionExecutor::buildRequestDataFromPsr($request, $this->controller->getContext());
        $stream = new \Quiote\Http\Sse\SseStream($action->streamEvents($webRequest));
        $factory = \Quiote\Http\Psr17::factory();
        return $factory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $dbg = \Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug);
        // Clear stale state from previous request on the shared global response so that
        // any HTTP status code we read back in buildPsrResponse() reflects only what the
        // current action/view cycle actually set.
        try {
            $globalResp = $this->controller->getGlobalResponse();
            $globalResp->clear();
        } catch (\Throwable $e) {
            // A response not cleared may still carry the previous request's status or headers
            // under a persistent worker, which is worth knowing about.
            \Quiote\Logging\Log::for($this)->warning(
                '[DispatchMiddleware] could not clear the global response before dispatch: ' . $e->getMessage()
            );
        }
        // Correlation ID (per-request) for tracing multi-request races
        $rid = $this->correlationId($request);
        if ($request->getAttribute('quiote.rid') === null) {
            $request = $request->withAttribute('quiote.rid', $rid);
        }
        // instanceof rather than a truthiness check: getAttribute() is typed
        // mixed, so these are also what narrow the values for everything below.
        $execStateAttr = $request->getAttribute(ExecutionState::class);
        $execState = $execStateAttr instanceof ExecutionState ? $execStateAttr : new ExecutionState();
        $request = $request->withAttribute(ExecutionState::class, $execState);
        $actionDesc = $request->getAttribute(ActionDescriptor::class);

        if (!$actionDesc instanceof ActionDescriptor) {
            $factory = \Quiote\Http\Psr17::factory();
            return $factory->createResponse(404)->withBody($factory->createStream('Not Found'));
        }

        \Quiote\Logging\Log::for($this)->debugWith(
            fn(): string => '[DispatchMiddleware][' . $rid . '] action=' . $actionDesc->module . ':' . $actionDesc->action
                . ' method=' . $actionDesc->method
                . ' simple=' . ($actionDesc->isSimple ? '1' : '0')
                . ' vd=' . ($execState->validationDecision->state ?? 'null')
                . ' sec=' . ($execState->securityDecision->name ?? 'null')
                . ' sessId=' . $this->diagnosticSessionId()
                . ' auth=' . $this->diagnosticAuthState()
        );
        // Non-simple actions require validation; allow pending if this is a forwarded target (ValidationMiddleware should run earlier in pipeline).
        if (!$actionDesc->isSimple) {
            if (!$execState->validationDecision || $execState->validationDecision->isPending()) {
                // Let execution proceed only if forwarded AND validation pipeline will run earlier (ensured by pipeline order); otherwise error.
                if (!$execState->forwarded) {
                    $factory = \Quiote\Http\Psr17::factory();
                    $resp = $factory->createResponse(500)->withBody($factory->createStream('Validation middleware missing'));
                    return $resp->withHeader('X-Quiote-Validation-State', $execState->validationDecision->state ?? 'absent')->withHeader('X-Quiote-Debug', 'validation-middleware-missing');
                }
            } elseif ($execState->validationDecision->isFailed()) {
                return $this->validationFailedResponse($request, $actionDesc, null);
            }
        }
        $resp = $actionDesc->isSimple ? $this->processSimple($request, $actionDesc) : $this->processNonSimple($request, $actionDesc);
        if (!$actionDesc->isSimple && $execState->validationDecision) {
            $resp = $resp->withHeader('X-Quiote-Validation-State', $execState->validationDecision->state);
        }

        if ($dbg) {
            \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware][' . $rid . '] response status=' . $resp->getStatusCode() . ' len=' . strlen((string)$resp->getBody()));
        }
        return $resp;
    }

    // appendTrace removed; centralised in FrameworkMiddlewarePipeline

    private function processSimple(ServerRequestInterface $request, ActionDescriptor $actionDesc): ResponseInterface
    {

        // Reuse existing ExecutionState if provided so prior middleware decisions (e.g., security) persist.
        $execState = $request->getAttribute(ExecutionState::class);
        if (!$execState instanceof ExecutionState) {
            $execState = new ExecutionState();
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
        if (!$execState->validationDecision) {
            $execState->validationDecision = ValidationDecision::passed();
        }
        $cacheEnabled = Config::getBool('core.cache_enabled', false);
        $useCache = $cacheEnabled && \Quiote\Config\Config::getBool('core.use_cache', false);
        $avCache = ($cacheEnabled && $useCache) ? new ActionViewCache(CacheManager::getCache()) : null;
        $cacheBypass = (bool)$request->getAttribute('quiote.cache.bypass');
        $locale = $this->currentCacheLocale();
        $cacheHitPayload = null;
        $isCacheable = false;
        $actionInstance = null;
        // false, not null: null means "share one entry", so a failure to get as far
        // as computeUserFingerprint() must not fall through to the shared entry.
        $userFp = false;
        try {
            // Reuse the action instance already created and initialized by SecurityMiddleware to
            // avoid a redundant instantiation per request.
            // Assigned only from the narrowed branches below, so $actionInstance
            // stays ?Action for the rest of the method rather than mixed.
            $preinstantiated = $request->getAttribute('quiote.preinstantiated_action');
            if ($preinstantiated instanceof \Quiote\Action\Action) {
                $actionInstance = $preinstantiated;
            } else {
                // SecurityMiddleware didn't set one (e.g. security disabled); create it now.
                $actionInstance = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
                $actionInstance->initialize(new LightweightActionInitContext(
                    $this->controller->getContext(),
                    $actionDesc->module,
                    $actionDesc->action,
                    $actionDesc->method,
                    $actionDesc->outputType,
                    $request,
                    $this->controller->getGlobalResponse()
                ));
            }
            $isCacheable = (bool)$actionInstance->isCacheable($actionDesc->outputType);
            $userFp = $this->computeUserFingerprint($actionInstance, $actionDesc->outputType);
        } catch (\Throwable $e) {
            // $isCacheable and $userFp keep their conservative defaults, so this dispatch runs
            // uncached rather than risking a wrongly-partitioned entry.
            \Quiote\Logging\Log::for($this)->warning(
                '[DispatchMiddleware] could not prepare the action for ' . $actionDesc->module . ':'
                . $actionDesc->action . '; dispatching uncached: ' . $e->getMessage()
            );
        }
        if ($actionInstance instanceof \Quiote\Http\Sse\SseStreamingAction) {
            return $this->buildSseResponse($actionInstance, $request);
        }
        // $userFp === false means the identity this output belongs to is unknown,
        // so neither reading nor writing the cache is safe.
        $cacheUsable = $isCacheable && $userFp !== false;
        $fingerprint = $userFp === false ? null : $userFp;
        if ($cacheEnabled && $cacheUsable && !$cacheBypass) {
            $cacheHitPayload = $avCache ? ActionCacheHelper::read($avCache, $actionDesc, $fingerprint, $locale) : null;
            if ($cacheHitPayload) {
                if ($this->dynamicFlagsActive($actionInstance)) {
                    $cacheHitPayload = null;
                }
            }
        }
        if ($cacheHitPayload) {
            $webRequest = ActionExecutor::buildRequestDataFromPsr($request, $this->controller->getContext());
            $ctx = ActionCacheHelper::buildContextFromPayload($cacheHitPayload, $actionDesc, $execState, $actionInstance, $webRequest);
            $execState->cacheHit = true;
            return $this->buildPsrResponse($ctx->content, $actionDesc->outputType, true, false);
        }
        // If prior SecurityMiddleware allowed, mark state so ActionExecutor skips its own security decision.
        if ($execState->securityDecision === null) {
            // Heuristic: presence of QUIOTE_SECURITY_DEBUG log decision=allow earlier isn't directly accessible; rely on user auth + secure action.
            try {
                $usr = $this->controller->getContext()->getContainer()->get(\Quiote\User\User::class);
                if ($actionInstance !== null && $actionInstance->isSecure() && method_exists($usr, 'isAuthenticated') && $usr->isAuthenticated()) {
                    $execState->securityDecision = SecurityDecision::Allow;
                }
            } catch (\Throwable $e) {
                // The decision is left as it was, which is the closed direction: this heuristic
                // only ever promotes to Allow.
                \Quiote\Logging\Log::for($this)->debug(
                    '[DispatchMiddleware] could not consult the user for the secure-action '
                    . 'allowance heuristic; leaving the decision unchanged: ' . $e->getMessage()
                );
            }
        }
        $ctx = $this->actionExecutor->execute($actionDesc, $request, $execState, [], $actionInstance);

        if ($cacheEnabled && $cacheUsable && !$execState->cacheHit && !$cacheBypass) {
            $ttl = $actionInstance?->cacheTtlSeconds($actionDesc->outputType);
            if ($avCache) {
                ActionCacheHelper::store($avCache, $actionDesc, $execState, $ctx->content, $this->stringKeyedAttributes($actionInstance), true, $ttl, $fingerprint, $locale);
            }
        }
        if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
            $rid = $this->correlationId($request);
            \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware][' . $rid . '] simple contentType=' . $actionDesc->outputType . ' contentLen=' . strlen($ctx->content) . ' prefix=' . substr($ctx->content, 0, 80));
        }
        return $this->buildPsrResponse($ctx->content, $actionDesc->outputType, false, false, $ctx->redirect ?? null);
    }

    /**
     * The current locale identifier for cache key purposes, or null when
     * translations are disabled/uninitialized. A cached action/view result is
     * specific to the locale it was rendered in, so this must factor into the
     * cache key -- see ActionViewCache::key().
     */
    private function currentCacheLocale(): ?string
    {
        try {
            return $this->controller->getContext()->getContainer()->tryGet(\Quiote\Translation\TranslationManager::class)?->getCurrentLocaleIdentifier();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * An action's attributes narrowed to the string-keyed shape the cache
     * helper expects, or an empty map when there is no action instance.
     *
     * @return array<string, mixed>
     */
    private function stringKeyedAttributes(?\Quiote\Action\Action $actionInstance): array
    {
        if ($actionInstance === null) {
            return [];
        }

        $result = [];
        foreach ($actionInstance->getAttributes() as $key => $value) {
            $result[(string)$key] = $value;
        }

        return $result;
    }

    /**
     * This request's correlation id for log lines, generating one when the
     * attribute is absent. Narrowed here because getAttribute() is typed mixed.
     */
    private function correlationId(ServerRequestInterface $request): string
    {
        $rid = $request->getAttribute('quiote.rid');
        if (is_string($rid) && $rid !== '') {
            return $rid;
        }

        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return uniqid();
        }
    }

    /**
     * Builds the 400 response for a failed validation decision reached here
     * without ValidationMiddleware having already rendered/short-circuited
     * (the normal path never reaches this; this is the container-less
     * pipeline's last-resort fallback). $providedContent, when given, is
     * rendered as-is (its Content-Type is still corrected for a JSON client);
     * otherwise a body is synthesized -- Problem Details for a client that
     * negotiated JSON, the legacy HTML fragment otherwise. A client that asked
     * for JSON and not HTML never receives the HTML fragment; see
     * {@see negotiatedJson()} for what counts as asking.
     */
    private function validationFailedResponse(ServerRequestInterface $request, ActionDescriptor $actionDesc, ?string $providedContent): ResponseInterface
    {
        $factory = \Quiote\Http\Psr17::factory();
        $wantsJson = $this->negotiatedJson($request, $actionDesc);
        if ($providedContent !== null) {
            $resp = $factory->createResponse(400)->withBody($factory->createStream($providedContent));
            return $wantsJson ? $resp->withHeader('Content-Type', \Quiote\Http\ProblemDetails::MEDIA_TYPE) : $resp;
        }
        if ($wantsJson) {
            $problem = \Quiote\Http\ProblemDetails::create(status: 400, detail: 'Validation failed.', instance: $request->getUri()->getPath());
            return $factory->createResponse(400)
                ->withHeader('Content-Type', \Quiote\Http\ProblemDetails::MEDIA_TYPE)
                ->withBody($factory->createStream($problem->toJson()));
        }
        return $factory->createResponse(400)->withBody($factory->createStream('<div>Validation Failed</div>'));
    }

    /**
     * Whether the client negotiated (or is asking for, via Accept) a JSON
     * response rather than HTML. Trusts the already-negotiated
     * ActionDescriptor->outputType first; falls back to the raw Accept
     * header when outputType wasn't resolved to 'html'/'json' specifically.
     *
     * The Accept fallback deliberately requires application/json AND the absence
     * of text/html, so HTML is what everything ambiguous gets: no Accept header
     * at all, `*​/*`, or a browser-style list naming both. That is the safe
     * default for the case we cannot distinguish -- a plain curl and a browser
     * look identical here -- and an application that wants otherwise says so per
     * action via its output type rather than having it inferred. Do not "fix"
     * this into preferring JSON for an absent Accept header: that is the chosen
     * behaviour, not an oversight.
     */
    private function negotiatedJson(ServerRequestInterface $request, ActionDescriptor $actionDesc): bool
    {
        $outputType = strtolower($actionDesc->outputType);
        if ($outputType === 'json') {
            return true;
        }
        if ($outputType === 'html') {
            return false;
        }
        $accept = strtolower($request->getHeaderLine('Accept'));
        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    private function processNonSimple(ServerRequestInterface $request, ActionDescriptor $actionDesc): ResponseInterface
    {
        //$rd = ActionExecutor::buildRequestDataFromPsr($request);
        $execStateAttr = $request->getAttribute(ExecutionState::class);
        $execState = $execStateAttr instanceof ExecutionState ? $execStateAttr : new ExecutionState();
        if (!$execState->validationDecision) {
            $execState->validationDecision = ValidationDecision::pending();
        }
        // Security decision must have been established by SecurityMiddleware. If missing and security disabled, executor will allow; otherwise treat as logic gap.
        if ($execState->validationDecision->isFailed() && $execState->viewName) {
            $contentRaw = $request->getAttribute('validation.error.content');
            $content = is_string($contentRaw) && $contentRaw !== '' ? $contentRaw : null;
            return $this->validationFailedResponse($request, $actionDesc, $content);
        }
        $avCache = null;
        $cacheHitPayload = null;
        $isCacheable = false;
        $actionInstance = null;
        // See the simple path: false rather than null so a failure before the
        // fingerprint is computed cannot fall through to the shared cache entry.
        $userFp = false;
        try {
            // Reuse the action instance already created and initialized by SecurityMiddleware.
            $preinstantiated = $request->getAttribute('quiote.preinstantiated_action');
            if ($preinstantiated instanceof \Quiote\Action\Action) {
                $actionInstance = $preinstantiated;
            } else {
                $actionInstance = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
                $actionInstance->initialize(new LightweightActionInitContext(
                    $this->controller->getContext(),
                    $actionDesc->module,
                    $actionDesc->action,
                    $actionDesc->method,
                    $actionDesc->outputType,
                    $request,
                    $this->controller->getGlobalResponse()
                ));
            }
            $isCacheable = (bool)$actionInstance->isCacheable($actionDesc->outputType);
            $userFp = $this->computeUserFingerprint($actionInstance, $actionDesc->outputType);
        } catch (\Throwable $e) {
            // $isCacheable and $userFp keep their conservative defaults, so this dispatch runs
            // uncached rather than risking a wrongly-partitioned entry.
            \Quiote\Logging\Log::for($this)->warning(
                '[DispatchMiddleware] could not prepare the action for ' . $actionDesc->module . ':'
                . $actionDesc->action . '; dispatching uncached: ' . $e->getMessage()
            );
        }
        if ($actionInstance instanceof \Quiote\Http\Sse\SseStreamingAction) {
            return $this->buildSseResponse($actionInstance, $request);
        }
        $cacheEnabled = Config::getBool('core.cache_enabled', false);
        $cacheBypass = (bool)$request->getAttribute('quiote.cache.bypass');
        $locale = $this->currentCacheLocale();
        $cacheUsable = $isCacheable && $userFp !== false;
        $fingerprint = $userFp === false ? null : $userFp;
        if ($cacheEnabled && $cacheUsable && !$cacheBypass) {
            $useCache = \Quiote\Config\Config::getBool('core.use_cache', false);
            $avCache = $useCache ? new ActionViewCache(CacheManager::getCache()) : null;
            $cacheHitPayload = $avCache ? ActionCacheHelper::read($avCache, $actionDesc, $fingerprint, $locale) : null;
            if ($cacheHitPayload) {
                $key = $actionDesc->module . ':' . $actionDesc->action . ':' . $actionDesc->outputType;
                if (!isset(self::$executedNonSimpleActions[$key])) {
                    $cacheHitPayload = null;
                }
                if ($this->dynamicFlagsActive($actionInstance)) {
                    $cacheHitPayload = null;
                }
            }
        }
        if ($cacheHitPayload) {
            // Build or synthesize an WebRequest for cache replay; fall back to a fresh instance if canonical not available.
            $webReq = null;
            try {
                $webReq = ActionExecutor::buildRequestDataFromPsr($request, $this->controller->getContext());
            } catch (\Throwable) {
                try { $webReq = new \Quiote\Request\WebRequest(); } catch (\Throwable) { $webReq = null; }
            }
            if (!($webReq instanceof \Quiote\Request\WebRequest)) {
                throw new \TypeError('WebRequest unavailable for cache replay');
            }
            $ctx = ActionCacheHelper::buildContextFromPayload($cacheHitPayload, $actionDesc, $execState, $actionInstance, $webReq);
        } else {
            $ctx = $this->actionExecutor->execute($actionDesc, $request, $execState, [], $actionInstance);
        }
        self::$executedNonSimpleActions[$actionDesc->module . ':' . $actionDesc->action . ':' . $actionDesc->outputType] = true;
        if ($cacheEnabled && $cacheUsable && !$execState->cacheHit && !$cacheBypass && $avCache) {
            $ttl = $actionInstance?->cacheTtlSeconds($actionDesc->outputType);
            ActionCacheHelper::store($avCache, $actionDesc, $execState, $ctx->content, $this->stringKeyedAttributes($actionInstance), false, $ttl, $fingerprint, $locale);
        }
        if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
            $rid = $this->correlationId($request);
            \Quiote\Logging\Log::for($this)->debug('[DispatchMiddleware][' . $rid . '] nonSimple contentType=' . $actionDesc->outputType . ' contentLen=' . strlen($ctx->content) . ' prefix=' . substr($ctx->content, 0, 80));
        }
        return $this->buildPsrResponse($ctx->content, $actionDesc->outputType, $execState->cacheHit, false, $ctx->redirect ?? null);
    }
    // runWithCaching & executeView removed with container elimination.
}
