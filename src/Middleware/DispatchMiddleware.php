<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
// Removed legacy container & request adapter usage.
use Agavi\Execution\ActionDescriptor;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\SecurityDecision;
use Agavi\Execution\ActionExecutor; // new container-less executor
use Agavi\Execution\ActionExecutionSession; // transitional session abstraction
use Agavi\Execution\LightweightActionInitContext; // lightweight init context for action/view
use Agavi\View\AgaviView;
use Agavi\Util\AgaviToolkit;
use Agavi\Config\AgaviConfig;
use Agavi\Cache\CacheManager;
use Agavi\Cache\ActionViewCache;
use Agavi\Execution\ActionCacheHelper;
use Agavi\Exception\AgaviException;
// AgaviUncacheableException no longer referenced here after container removal.
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Execution\ValidationDecision;

/**
 * DispatchMiddleware replaces the legacy global filter chain + dispatch filter.
 * Legacy AgaviExecutionContainer execution path has been removed. All actions run through
 * the container-less ActionExecutor (simple and non-simple). Caching operates solely on
 * executor output; no legacy container response objects are produced anymore.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'action', after: 'SecurityMiddleware')]
class DispatchMiddleware implements MiddlewareInterface
{
    private ?ActionExecutor $actionExecutor = null;
    private static array $executedSimpleActions = [];
    private static array $executedNonSimpleActions = [];

    // Forward loop protection moved to SecurityMiddleware (forwardCount in ExecutionState)

    public function __construct(private AgaviController $controller)
    {
        // Always use ActionExecutor; legacy container path removed unconditionally.
        $this->actionExecutor = new ActionExecutor($controller);
    }

    private function computeUserFingerprint($actionInstance): ?string
    {
        try {
            if (!$actionInstance || !method_exists($actionInstance, 'isSecure') || !$actionInstance->isSecure()) {
                return null;
            }
            $user = $this->controller->getContext()->getUser();
            if (!$user) {
                return 'guest';
            }
            $bits = [];
            if (method_exists($user, 'isAuthenticated')) {
                $bits[] = $user->isAuthenticated() ? 'auth:1' : 'auth:0';
            }
            // Removed credential fingerprinting due to container elimination and interface variability.
            if (!$bits) {
                return 'anon';
            }
            return sha1(implode('|', $bits));
        } catch (\Throwable) {
            return null;
        }
    }

    private function dynamicFlagsActive($actionInstance): bool
    {
        try {
            if (!$actionInstance) {
                return false;
            }
            $cls = get_class($actionInstance);
            foreach (['failValidation', 'requireAuth', 'requireCred'] as $p) {
                if (property_exists($cls, $p)) {
                    $rp = new \ReflectionProperty($cls, $p);
                    if ($rp->isStatic() && $rp->getValue() === true) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private function buildPsrResponse(string $content, string $outputType, bool $cacheHit, bool $containerUsed): ResponseInterface
    {
        
        $factory = new Psr17Factory();
        $status = 200;

        // TODO: propagate status from global response once unified interface available
        $resp = $factory->createResponse($status)->withBody($factory->createStream($content));
        
        // Set Content-Type and other headers from the AgaviOutputType configuration
        try {
            $ot = $this->controller->getOutputType($outputType);
            if ($ot) {
                $httpHeaders = $ot->getParameter('http_headers', []);
                if (is_array($httpHeaders)) {
                    foreach ($httpHeaders as $name => $value) {
                        $resp = $resp->withHeader($name, $value);
                    }
                }
                if (getenv('AGAVI_DEBUG_RESPONSE') || getenv('AGAVI_DEBUG_DISPATCH')) {
                    AgaviDebugLogger::debug('[DispatchMiddleware.buildPsrResponse] set headers from output type ' . $outputType . ': ' . json_encode($httpHeaders), $this->controller->getContext());
                }
            } else {
                if (getenv('AGAVI_DEBUG_RESPONSE') || getenv('AGAVI_DEBUG_DISPATCH')) {
                    AgaviDebugLogger::debug('[DispatchMiddleware.buildPsrResponse] getOutputType(' . $outputType . ') returned null', $this->controller->getContext());
                }             
            }
        } catch (\Throwable $e) {
            if (getenv('AGAVI_DEBUG_RESPONSE') || getenv('AGAVI_DEBUG_DISPATCH')) {
                AgaviDebugLogger::debug('[DispatchMiddleware.buildPsrResponse] exception getting output type: ' . $e->getMessage(), $this->controller->getContext());
            }            
        }
        
        $disableHeaders = (bool)AgaviConfig::get('core.disable-framework-headers', false);
        if (!$disableHeaders) {
            $cacheHitHeader = AgaviConfig::get('core.cache-hit-header', 'X-Agavi-Cache-Hit');
            if ($cacheHit && $cacheHitHeader) {
                $resp = $resp->withHeader($cacheHitHeader, '1');
            }
        }

        // Bridge any cookies scheduled on the legacy Agavi WebResponse into PSR response headers
        try {
            $globalResp = $this->controller->getGlobalResponse();
            if (is_object($globalResp) && method_exists($globalResp, 'getCookies')) {
                $cookies = is_callable([$globalResp, 'getCookies']) ? $globalResp->{'getCookies'}() : [];
                $routing = $this->controller->getContext()->getRouting();
                $basePath = method_exists($routing, 'getBasePath') ? $routing->getBasePath() : '/';
                foreach ($cookies as $name => $values) {
                    try {
                        // Determine expiration
                        if (is_string($values['lifetime'])) {
                            $expire = strtotime($values['lifetime']);
                        } else {
                            $expire = ($values['lifetime'] != 0) ? time() + (int)$values['lifetime'] : 0;
                        }
                        if ($values['value'] === false || $values['value'] === null || $values['value'] === '') {
                            $expire = time() - 3600 * 24;
                        }
                        // Apply encode callback if present and value is not null
                        $val = $values['value'];
                        if ($val !== null && !empty($values['encode_callback']) && $values['encode_callback'] !== false && is_callable($values['encode_callback'])) {
                            $val = call_user_func($values['encode_callback'], $val);
                        }
                        $path = $values['path'] === null ? $basePath : $values['path'];
                        // Build Set-Cookie string
                        if ($val !== null) {
                            $cookieStr = $name . '=' . (string)$val;
                            if ($expire > 0) {
                                $cookieStr .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', $expire) . '; Max-Age=' . max(0, $expire - time());
                            }
                            $cookieStr .= '; Path=' . ($path ?: '/');
                            if (!empty($values['domain'])) {
                                $cookieStr .= '; Domain=' . $values['domain'];
                            }
                            if (!empty($values['secure'])) {
                                $cookieStr .= '; Secure';
                            }
                            if (!empty($values['httponly'])) {
                                $cookieStr .= '; HttpOnly';
                            }
                            if (!empty($values['samesite'])) {
                                $cookieStr .= '; SameSite=' . ucfirst(strtolower((string)$values['samesite']));
                            }
                            $existing = method_exists($resp, 'getHeader') ? $resp->getHeader('Set-Cookie') : [];
                            if (!in_array($cookieStr, $existing, true)) {
                                $resp = $resp->withAddedHeader('Set-Cookie', $cookieStr);
                            }
                        }
                    } catch (\Throwable) {
                        // ignore cookie formatting errors per-request
                    }
                }
            }
        } catch (\Throwable) {
        }
        return $resp;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $dbg = getenv('AGAVI_DEBUG_DISPATCH');
        // Correlation ID (per-request) for tracing multi-request races
        if (!$request->getAttribute('agavi.rid')) {
            try {
                $rid = bin2hex(random_bytes(4));
            } catch (\Throwable) {
                $rid = uniqid();
            }
            $request = $request->withAttribute('agavi.rid', $rid);
        } else {
            $rid = $request->getAttribute('agavi.rid');
        }
        $execState = $request->getAttribute(ExecutionState::class) ?? new ExecutionState();
        $request = $request->withAttribute(ExecutionState::class, $execState);
        $actionDesc = $request->getAttribute(ActionDescriptor::class);

        if (!$actionDesc) {
            $factory = new Psr17Factory();
            return $factory->createResponse(404)->withBody($factory->createStream('Not Found'));
        }

        if ($dbg) {
            $sessId = 'no-sid';
            try {
                $storage = $this->controller->getContext()->getStorage();
                if ($storage && method_exists($storage, 'getId')) {
                    $sidTmp = $storage->getId();
                    if (is_string($sidTmp) && $sidTmp !== '') {
                        $sessId = $sidTmp;
                    }
                }
            } catch (\Throwable) {
            }
            if ($sessId === 'no-sid' && function_exists('session_id')) {
                try {
                    $sidNative = session_id();
                    if (is_string($sidNative) && $sidNative !== '') {
                        $sessId = $sidNative;
                    }
                } catch (\Throwable) {
                }
            }
            $auth = 'na';
            try {
                $u = $this->controller->getContext()->getUser();
                if ($u && method_exists($u, 'isAuthenticated')) {
                    $auth = $u->isAuthenticated() ? '1' : '0';
                }
            } catch (\Throwable) {
            }
            AgaviDebugLogger::debug('[DispatchMiddleware][' . $rid . '] action=' . $actionDesc->module . ':' . $actionDesc->action . ' method=' . $actionDesc->method . ' simple=' . ($actionDesc->isSimple ? '1' : '0') . ' vd=' . ($execState->validationDecision?->state ?? 'null') . ' sec=' . ($execState->securityDecision?->name ?? 'null') . ' sessId=' . $sessId . ' auth=' . $auth, $this->controller->getContext());
        }
        // Non-simple actions require validation; allow pending if this is a forwarded target (ValidationMiddleware should run earlier in pipeline).
        if (!$actionDesc->isSimple) {
            if (!$execState->validationDecision || $execState->validationDecision->isPending()) {
                // Let execution proceed only if forwarded AND validation pipeline will run earlier (ensured by pipeline order); otherwise error.
                if (!$execState->forwarded) {
                    $factory = new Psr17Factory();
                    $resp = $factory->createResponse(500)->withBody($factory->createStream('Validation middleware missing'));
                    return $resp->withHeader('X-Agavi-Validation-State', $execState->validationDecision?->state ?? 'absent')->withHeader('X-Agavi-Debug', 'validation-middleware-missing');
                }
            } elseif ($execState->validationDecision->isFailed()) {
                $factory = new Psr17Factory();
                return $factory->createResponse(400)->withBody($factory->createStream('<div>Validation Failed</div>'));
            }
        }
        $resp = $actionDesc->isSimple ? $this->processSimple($request, $actionDesc) : $this->processNonSimple($request, $actionDesc);
        if (!$actionDesc->isSimple && $execState->validationDecision) {
            $resp = $resp->withHeader('X-Agavi-Validation-State', $execState->validationDecision->state);
        }

        if ($dbg && method_exists($resp, 'getBody')) {
            AgaviDebugLogger::debug('[DispatchMiddleware][' . $rid . '] response status=' . $resp->getStatusCode() . ' len=' . strlen((string)$resp->getBody()), $this->controller->getContext());
        }
        return $resp;
    }

    // appendTrace removed; centralised in FrameworkMiddlewarePipeline

    private function processSimple(ServerRequestInterface $request, ActionDescriptor $actionDesc): ResponseInterface
    {

        $webRequest = ActionExecutor::buildRequestDataFromPsr($request);
        // Reuse existing ExecutionState if provided so prior middleware decisions (e.g., security) persist.
        $execState = $request->getAttribute(ExecutionState::class);
        if (!$execState) {
            $execState = new ExecutionState();
            $request = $request->withAttribute(ExecutionState::class, $execState);
        }
        if (!$execState->validationDecision) {
            $execState->validationDecision = ValidationDecision::passed();
        }
        $cacheEnabled = (bool)AgaviConfig::get('core.cache_enabled', false);
        $useCache = $cacheEnabled && \Agavi\Config\AgaviConfig::get('core.use_cache', false);
        $avCache = ($cacheEnabled && $useCache) ? new ActionViewCache(CacheManager::getCache()) : null;
        $cacheHitPayload = null;
        $isCacheable = false;
        $actionInstance = null;
        $userFp = null;
        try {
            $actionInstance = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
            if (method_exists($actionInstance, 'initialize')) {
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
            $userFp = $this->computeUserFingerprint($actionInstance);
        } catch (\Throwable) {
        }
        if ($cacheEnabled && $isCacheable && !$request->getAttribute('agavi.cache.bypass')) {
            $cacheHitPayload = $avCache ? ActionCacheHelper::read($avCache, $actionDesc, $userFp) : null;
            if ($cacheHitPayload) {
                if ($this->dynamicFlagsActive($actionInstance)) {
                    $cacheHitPayload = null;
                }
            }
        }
        if ($cacheHitPayload) {
            if ($cacheHitPayload) {
                $ctx = ActionCacheHelper::buildContextFromPayload($cacheHitPayload, $actionDesc, $execState, $actionInstance, $webRequest);
            }
            $execState->cacheHit = true;
            return $this->buildPsrResponse($ctx->content, $actionDesc->outputType, true, false);
        }
        // If prior SecurityMiddleware allowed, mark state so ActionExecutor skips its own security decision.
        if ($execState->securityDecision === null) {
            // Heuristic: presence of AGAVI_SECURITY_DEBUG log decision=allow earlier isn't directly accessible; rely on user auth + secure action.
            try {
                $usr = $this->controller->getContext()->getUser();
                if ($actionInstance && method_exists($actionInstance, 'isSecure') && $actionInstance->isSecure() && $usr && method_exists($usr, 'isAuthenticated') && $usr->isAuthenticated()) {
                    $execState->securityDecision = SecurityDecision::Allow;
                }
            } catch (\Throwable) {
            }
        }
        $ctx = $this->actionExecutor->execute($actionDesc, $request, $execState, [], $actionInstance);

        self::$executedSimpleActions[$actionDesc->module . ':' . $actionDesc->action . ':' . $actionDesc->outputType] = true;
        if ($cacheEnabled && $isCacheable && !$execState->cacheHit) {
            $ttl = method_exists($actionInstance, 'cacheTtlSeconds') ? $actionInstance->cacheTtlSeconds($actionDesc->outputType) : null;
            if ($avCache) {
                ActionCacheHelper::store($avCache, $actionDesc, $execState, $ctx->content, ($actionInstance && method_exists($actionInstance, 'getAttributes')) ? $actionInstance->getAttributes() : [], true, $ttl, $userFp);
            }
        }
        if (getenv('AGAVI_DEBUG_DISPATCH')) {
            $rid = $request->getAttribute('agavi.rid');
            AgaviDebugLogger::debug('[DispatchMiddleware][' . $rid . '] simple contentType=' . $actionDesc->outputType . ' contentLen=' . strlen($ctx->content) . ' prefix=' . substr($ctx->content, 0, 80), $this->controller->getContext());
        }
        return $this->buildPsrResponse($ctx->content, $actionDesc->outputType, false, false);
    }

    private function processNonSimple(ServerRequestInterface $request, ActionDescriptor $actionDesc): ResponseInterface
    {
        //$rd = ActionExecutor::buildRequestDataFromPsr($request);
        $execState = $request->getAttribute(ExecutionState::class) ?? new ExecutionState();
        if (!$execState->validationDecision) {
            $execState->validationDecision = ValidationDecision::pending();
        }
        // Security decision must have been established by SecurityMiddleware. If missing and security disabled, executor will allow; otherwise treat as logic gap.
        if ($execState->validationDecision && $execState->validationDecision->isFailed() && $execState->viewName) {
            $content = (string)($request->getAttribute('validation.error.content') ?? '<div>Validation Failed</div>');
            $factory = new Psr17Factory();
            return $factory->createResponse(400)->withBody($factory->createStream($content));
        }
        $avCache = null;
        $cacheHitPayload = null;
        $isCacheable = false;
        $actionInstance = null;
        $userFp = null;
        try {
            $actionInstance = $this->controller->createActionInstance($actionDesc->module, $actionDesc->action);
            if (method_exists($actionInstance, 'initialize')) {
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
            $userFp = $this->computeUserFingerprint($actionInstance);
        } catch (\Throwable) {
        }
        $cacheEnabled = (bool)AgaviConfig::get('core.cache_enabled', false);
        if ($cacheEnabled && $isCacheable && !$request->getAttribute('agavi.cache.bypass')) {
            $useCache = \Agavi\Config\AgaviConfig::get('core.use_cache', false);
            $avCache = $useCache ? new ActionViewCache(CacheManager::getCache()) : null;
            $cacheHitPayload = $avCache ? ActionCacheHelper::read($avCache, $actionDesc, $userFp) : null;
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
            if ($cacheHitPayload) {
                // Build or synthesize an AgaviWebRequest for cache replay; fall back to a fresh instance if canonical not available.
                $webReq = null;
                try {
                    $webReq = ActionExecutor::buildRequestDataFromPsr($request);
                } catch (\Throwable) {
                    try { $webReq = new \Agavi\Request\AgaviWebRequest(); } catch (\Throwable) { $webReq = null; }
                }
                if (!($webReq instanceof \Agavi\Request\AgaviWebRequest)) {
                    throw new \TypeError('AgaviWebRequest unavailable for cache replay');
                }
                $ctx = ActionCacheHelper::buildContextFromPayload($cacheHitPayload, $actionDesc, $execState, $actionInstance, $webReq);
            }
        } else {
            $ctx = $this->actionExecutor->execute($actionDesc, $request, $execState);
        }
        self::$executedNonSimpleActions[$actionDesc->module . ':' . $actionDesc->action . ':' . $actionDesc->outputType] = true;
        if ($cacheEnabled && $isCacheable && !$execState->cacheHit && $avCache) {
            $ttl = method_exists($actionInstance, 'cacheTtlSeconds') ? $actionInstance->cacheTtlSeconds($actionDesc->outputType) : null;
            if ($avCache) {
                ActionCacheHelper::store($avCache, $actionDesc, $execState, $ctx->content, ($actionInstance && method_exists($actionInstance, 'getAttributes')) ? $actionInstance->getAttributes() : [], false, $ttl, $userFp);
            }
        }
        if (getenv('AGAVI_DEBUG_DISPATCH')) {
            $rid = $request->getAttribute('agavi.rid');
            AgaviDebugLogger::debug('[DispatchMiddleware][' . $rid . '] nonSimple contentType=' . $actionDesc->outputType . ' contentLen=' . strlen($ctx->content) . ' prefix=' . substr($ctx->content, 0, 80), $this->controller->getContext());
        }
        return $this->buildPsrResponse($ctx->content, $actionDesc->outputType, $execState->cacheHit, false);
    }
    // runWithCaching & executeView removed with container elimination.

    private function dbg($msg): void
    {
        if (!getenv('DM_DEBUG')) {
            return;
        }
        if (!getenv('DM_DEBUG_VERBOSE')) {
            foreach (['normalized single caching element', 'normalized cachings list', 'normalized views canonical', 'post-include config keys=', 'determined groups=', 'actionGroups=', 'view cacheability check', 'loaded action cache keys=', 'set view from action cache'] as $needle) {
                if (str_contains($msg, $needle)) {
                    return;
                }
            }
        }
        @file_put_contents('/tmp/dispatch_mw_debug.log', '[' . getmypid() . '] ' . $msg . "\n", FILE_APPEND);
    }
}
