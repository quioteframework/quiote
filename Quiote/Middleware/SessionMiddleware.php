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
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 900)]
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Controller $controller) {}

    /**
     * Parse a raw `Cookie:` header string into a name => value map.
     * preg_split() can return false (e.g. a PCRE backtrack-limit error on a pathological
     * header); in that case there are no usable pairs to mirror into $_COOKIE.
     * @return array<string, string>
     */
    private static function parseCookieHeader(string $cookieStr): array
    {
        $pairs = preg_split('/;\s*/', $cookieStr);
        if ($pairs === false) {
            return [];
        }
        $result = [];
        foreach ($pairs as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) { continue; }
            $k = trim(substr($pair, 0, $eq));
            $v = trim(substr($pair, $eq + 1));
            if ($k !== '') { $result[$k] = urldecode($v); }
        }
        return $result;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip session handling entirely for a stateless machine/service-client
        // request. `jwt.skip_session` is the original attribute name;
        // `auth.sessionless` is the generalized replacement set by
        // Quiote\Security\Auth\Middleware\StatelessAuthenticationMiddleware
        // (packages/auth) for a sessionless firewall or a service-typed token,
        // which runs earlier in the pipeline (before: SessionMiddleware::class).
        // Both are honored so neither an app still setting the legacy
        // attribute nor packages/auth's generalized one is silently ignored.
        if ($request->getAttribute('jwt.skip_session') || $request->getAttribute('auth.sessionless')) {
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
                if (!empty($psrCookies)) {
                    foreach ($psrCookies as $k => $v) { $_COOKIE[$k] = $v; }
                } else {
                    // Fallback: parse raw Cookie header if cookie params are empty (some PSR stacks don't populate cookie params)
                    $cookieHeaders = $request->getHeader('Cookie');
                    if (!empty($cookieHeaders)) {
                        foreach (self::parseCookieHeader(implode('; ', $cookieHeaders)) as $k => $v) {
                            $_COOKIE[$k] = $v;
                        }
                    }
                }
            } catch (\Throwable) {}
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                try { \Quiote\Logging\Log::for($this)->debug('[SessionMiddleware] mirrored $_COOKIE=' . var_export($_COOKIE, true)); } catch (\Throwable) {}
            }
            if (session_status() !== PHP_SESSION_ACTIVE) {
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
            // storage->shutdown may queue cookies into Quiote WebResponse
            $storage->shutdown();
            // Bridge queued cookies from WebResponse to PSR response if present
            $globalResp = null;
            try { $globalResp = $this->controller->getGlobalResponse(); } catch (\Throwable) { $globalResp = null; }
            if (is_object($globalResp)) {
                try {
                    $routing = $this->controller->getContext()->getRouting();
                    $basePath = $routing->getBasePath();
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
