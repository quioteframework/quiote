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
            if (getenv('AGAVI_DEBUG_SESSION')) {
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
            if (getenv('AGAVI_DEBUG_SESSION')) {
                try { AgaviDebugLogger::debug('[SessionMiddleware] mirrored $_COOKIE=' . var_export($_COOKIE, true), $this->controller->getContext()); } catch (\Throwable) {}
            }
            if (method_exists($storage, 'startup') && session_status() !== PHP_SESSION_ACTIVE) {
                if (getenv('AGAVI_DEBUG_SESSION')) { AgaviDebugLogger::debug('[SessionMiddleware] calling storage->startup()', $this->controller->getContext()); }
                $storage->startup();
                if (getenv('AGAVI_DEBUG_SESSION')) { AgaviDebugLogger::debug('[SessionMiddleware] storage->startup() returned; session id=' . var_export(method_exists($storage,'getId') ? $storage->getId() : null, true), $this->controller->getContext()); }
            }
        } catch (\Throwable $t) {
            if (getenv('AGAVI_DEBUG_SESSION')) {
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
            if (is_object($globalResp) && method_exists($globalResp, 'getCookies')) {
                try {
                    $cookies = is_callable([$globalResp, 'getCookies']) ? $globalResp->{'getCookies'}() : [];
                    // Determine base path for cookie default
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
                            // Apply encode callback if present and value non-null
                            $val = $values['value'];
                            if ($val !== null && !empty($values['encode_callback']) && $values['encode_callback'] !== false && is_callable($values['encode_callback'])) {
                                $val = call_user_func($values['encode_callback'], $val);
                            }
                            $path = $values['path'] === null ? $basePath : $values['path'];
                            if ($val !== null) {
                                $cookieStr = $name . '=' . (string)$val;
                                if ($expire > 0) {
                                    $cookieStr .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', $expire) . '; Max-Age=' . max(0, $expire - time());
                                }
                                $cookieStr .= '; Path=' . ($path ?: '/');
                                if (!empty($values['domain'])) { $cookieStr .= '; Domain=' . $values['domain']; }
                                if (!empty($values['secure'])) { $cookieStr .= '; Secure'; }
                                if (!empty($values['httponly'])) { $cookieStr .= '; HttpOnly'; }
                                if (!empty($values['samesite'])) {
                                    $cookieStr .= '; SameSite=' . ucfirst(strtolower((string)$values['samesite']));
                                }
                                $existing = method_exists($response, 'getHeader') ? $response->getHeader('Set-Cookie') : [];
                                if (!in_array($cookieStr, $existing, true)) {
                                    $response = $response->withAddedHeader('Set-Cookie', $cookieStr);
                                }
                            }
                        } catch (\Throwable) {
                            // ignore per-cookie errors
                        }
                    }
                } catch (\Throwable) {
                    // ignore cookie bridging errors
                }
            }
        } catch (\Throwable $t) {
            if (getenv('AGAVI_DEBUG_SESSION')) { AgaviDebugLogger::debug('[SessionMiddleware] shutdown error: ' . $t->getMessage(), $this->controller->getContext()); }
        }

        return $response;
    }
}

?>
