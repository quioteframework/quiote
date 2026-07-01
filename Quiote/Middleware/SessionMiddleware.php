<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\Controller;
use Quiote\Execution\ExecutionState;

/**
 * SessionMiddleware: ensures session storage is started and ExecutionState present before security.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', after: 'RoutingMiddleware', before: 'SecurityMiddleware')]
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Controller $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip session handling entirely for JWT-authenticated requests
        if ($request->getAttribute('jwt.skip_session')) {
            if (!$request->getAttribute(ExecutionState::class)) {
                $request = $request->withAttribute(ExecutionState::class, new ExecutionState());
            }
            return $handler->handle($request);
        }

        // Start session storage if not yet started for this request lifecycle.
        try {
            $storage = $this->controller->getContext()->getStorage();
            // Debug: show PSR cookie params and raw Cookie header
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                try {
                    \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] PSR cookie params=' . var_export($request->getCookieParams(), true));
                    \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] Cookie header=' . var_export($request->getHeader('Cookie'), true));
                } catch (\Throwable) {}
            }
            // If PSR cookie params are available, mirror them into $_COOKIE so legacy adapter fallback can read them.
            try {
                $psrCookies = $request->getCookieParams();
                if (is_array($psrCookies) && !empty($psrCookies)) {
                    foreach ($psrCookies as $k => $v) { $_COOKIE[$k] = $v; }
                } else {
                    // Fallback: parse raw Cookie header if cookie params are empty (some PSR stacks don't populate cookie params)
                    $cookieHeaders = $request->getHeader('Cookie');
                    if (!empty($cookieHeaders)) {
                        $cookieStr = implode('; ', $cookieHeaders);
                        $pairs = preg_split('/;\s*/', $cookieStr);
                        foreach ($pairs as $pair) {
                            $eq = strpos($pair, '=');
                            if ($eq === false) { continue; }
                            $k = trim(substr($pair, 0, $eq));
                            $v = trim(substr($pair, $eq + 1));
                            if ($k !== '') { $_COOKIE[$k] = urldecode($v); }
                        }
                    }
                }
            } catch (\Throwable) {}
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                try { \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] mirrored $_COOKIE=' . var_export($_COOKIE, true)); } catch (\Throwable) {}
            }
            if (method_exists($storage, 'startup') && session_status() !== PHP_SESSION_ACTIVE) {
                if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) { \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] calling storage->startup()'); }
                $storage->startup();
                if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) { \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] storage->startup() returned; session id=' . var_export(method_exists($storage,'getId') ? $storage->getId() : null, true)); }
            }
        } catch (\Throwable $t) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] startup error: ' . $t->getMessage());
            }
        }
        // Ensure ExecutionState exists.
        if(!$request->getAttribute(ExecutionState::class)) {
            $request = $request->withAttribute(ExecutionState::class, new ExecutionState());
        }
        // Let the rest of the pipeline run and capture the PSR response.
        $response = $handler->handle($request);

        // After handler completes, ensure storage shutdown/persistence runs and bridge any queued cookies
        try {
            $storage = $this->controller->getContext()->getStorage();
            if (method_exists($storage, 'shutdown')) {
                // storage->shutdown may queue cookies into Quiote WebResponse
                $storage->shutdown();
            }
            // Bridge queued cookies from WebResponse to PSR response if present
            $globalResp = null;
            try { $globalResp = $this->controller->getGlobalResponse(); } catch (\Throwable) { $globalResp = null; }
            if (is_object($globalResp)) {
                try {
                    $routing = $this->controller->getContext()->getRouting();
                    $basePath = method_exists($routing, 'getBasePath') ? $routing->getBasePath() : '/';
                    $response = \Quiote\Http\CookieSerializer::bridge($globalResp, $response, $basePath);
                } catch (\Throwable) {}
            }
        } catch (\Throwable $t) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) { \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] shutdown error: ' . $t->getMessage()); }
        }

        return $response;
    }
}

?>
