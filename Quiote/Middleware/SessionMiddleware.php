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
    private \Quiote\Logging\CategoryLogger $logger;

    public function __construct(private readonly Controller $controller)
    {
        $this->logger = \Quiote\Logging\Log::for($this);
    }

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
            try {
                return $handler->handle($request);
            } finally {
                // There is no session to persist into, so the user is not
                // written -- a token-derived identity must not be pushed into
                // whatever unrelated session cookie the client still carries.
                // The flush is still claimed, so the post-emit Context::reset()
                // does not attempt a late write of its own.
                try {
                    $this->controller->getContext()->flushRequestState(persistUser: false);
                } catch (\Throwable) {}
            }
        }

        // Start session storage if not yet started for this request lifecycle.
        $vd = $this->logger->isEnabled(\Quiote\Logging\Level::Debug);
        try {
            $storage = $this->controller->getContext()->getStorage();
            // Debug: show PSR cookie params and raw Cookie header
            if ($vd) {
                try {
                    $this->logger->debug('[SessionMiddleware] PSR cookie params=' . var_export($request->getCookieParams(), true));
                    $this->logger->debug('[SessionMiddleware] Cookie header=' . var_export($request->getHeader('Cookie'), true));
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
            if ($vd) {
                try { $this->logger->debug('[SessionMiddleware] mirrored $_COOKIE=' . var_export($_COOKIE, true)); } catch (\Throwable) {}
            }
            if (session_status() !== PHP_SESSION_ACTIVE) {
                if ($vd) { $this->logger->debug('[SessionMiddleware] calling storage->startup()'); }
                $storage->startup();
                if ($vd) { $this->logger->debug('[SessionMiddleware] storage->startup() returned; session id=' . $this->controller->getContext()->getSessionBag()->getId()); }
            }
        } catch (\Throwable $t) {
            if ($vd) {
                $this->logger->debug('[SessionMiddleware] startup error: ' . $t->getMessage());
            }
        }
        // Ensure ExecutionState exists.
        if(!$request->getAttribute(ExecutionState::class)) {
            $request = $request->withAttribute(ExecutionState::class, new ExecutionState());
        }
        // Let the rest of the pipeline run and capture the PSR response. The
        // flush below is in a finally so a throwing handler still persists and
        // closes the session before the error middleware emits its response.
        try {
            $response = $handler->handle($request);
        } finally {
            try {
                // Persist the user, THEN close the session -- in that order,
                // and here, while the response has not been emitted yet.
                // Doing only the storage half here (and leaving the user to
                // Context::reset(), which runs after emission) is what caused
                // roles and credentials to be written into a session nothing
                // would ever persist: an authenticated user with no
                // credentials, and a 403 on every subsequent request.
                //
                // Note this deliberately does NOT go through getStorage():
                // post-response, that would recreate a storage object that
                // reset() had already nulled.
                $this->controller->getContext()->flushRequestState();
            } catch (\Throwable $t) {
                if ($vd) {
                    $this->logger->debug('[SessionMiddleware] flush error: ' . $t->getMessage());
                }
            }
        }

        // Bridge any cookies the request queued onto the PSR response.
        try {
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
            if ($this->logger->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger->debug('[SessionMiddleware] shutdown error: ' . $t->getMessage()); }
        }

        return $response;
    }
}

?>
