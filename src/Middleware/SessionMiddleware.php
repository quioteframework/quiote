<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Agavi\Execution\ExecutionState;
use Agavi\Logging\AgaviDebugLogger;

/**
 * SessionMiddleware: ensures session storage is started and ExecutionState present before security.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'before_action', after: 'RoutingMiddleware', before: 'SecurityMiddleware')]
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Start session storage if not yet started for this request lifecycle.
        try {
            $storage = $this->controller->getContext()->getStorage();
            // Debug: show PSR cookie params and raw Cookie header
            if (\Agavi\Util\DebugFlags::$session) {
                try {
                    AgaviDebugLogger::debug('[SessionMiddleware] PSR cookie params=' . var_export($request->getCookieParams(), true), $this->controller->getContext());
                    AgaviDebugLogger::debug('[SessionMiddleware] Cookie header=' . var_export($request->getHeader('Cookie'), true), $this->controller->getContext());
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
            if (\Agavi\Util\DebugFlags::$session) {
                try { AgaviDebugLogger::debug('[SessionMiddleware] mirrored $_COOKIE=' . var_export($_COOKIE, true), $this->controller->getContext()); } catch (\Throwable) {}
            }
            if (method_exists($storage, 'startup') && session_status() !== PHP_SESSION_ACTIVE) {
                if (\Agavi\Util\DebugFlags::$session) { AgaviDebugLogger::debug('[SessionMiddleware] calling storage->startup()', $this->controller->getContext()); }
                $storage->startup();
                if (\Agavi\Util\DebugFlags::$session) { AgaviDebugLogger::debug('[SessionMiddleware] storage->startup() returned; session id=' . var_export(method_exists($storage,'getId') ? $storage->getId() : null, true), $this->controller->getContext()); }
            }
        } catch (\Throwable $t) {
            if (\Agavi\Util\DebugFlags::$session) {
                AgaviDebugLogger::debug('[SessionMiddleware] startup error: ' . $t->getMessage(), $this->controller->getContext());
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
                // storage->shutdown may queue cookies into Agavi WebResponse
                $storage->shutdown();
            }
            // Bridge queued cookies from AgaviWebResponse to PSR response if present
            $globalResp = null;
            try { $globalResp = $this->controller->getGlobalResponse(); } catch (\Throwable) { $globalResp = null; }
            if (is_object($globalResp)) {
                try {
                    $routing = $this->controller->getContext()->getRouting();
                    $basePath = method_exists($routing, 'getBasePath') ? $routing->getBasePath() : '/';
                    $response = \Agavi\Http\CookieSerializer::bridge($globalResp, $response, $basePath);
                } catch (\Throwable) {}
            }
        } catch (\Throwable $t) {
            if (\Agavi\Util\DebugFlags::$session) { AgaviDebugLogger::debug('[SessionMiddleware] shutdown error: ' . $t->getMessage(), $this->controller->getContext()); }
        }

        return $response;
    }
}

?>
