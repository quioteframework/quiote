<?php

namespace Quiote\Security\Csrf\Middleware;

use Quiote\Controller\Controller;
use Quiote\Security\Csrf\CsrfManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies a CSRF token on every unsafe (state-changing) request before the
 * action is dispatched. Safe methods (GET/HEAD/OPTIONS/TRACE) pass through.
 * The token is read from the configured form field (parsed body) or the
 * configured header (for XHR/fetch clients) and validated against the
 * session-stored token via {@see CsrfManager}. On failure the request is
 * short-circuited with HTTP 403 and the action never runs.
 * CSRF exists to stop an attacker site from riding a victim's ambient,
 * automatically-attached session cookie. Two classes of request fall outside
 * that threat model and are exempted automatically, without needing a
 * per-route opt-out:
 *   - Requests carrying an Authorization header. A cross-site attacker page
 *     cannot read or attach the caller's bearer/basic credential the way a
 *     browser auto-attaches a session cookie, so token/signature-authenticated
 *     callers (JWT, API keys, OAuth2 bearer tokens) are never forgeable.
 *   - Requests with no session cookie at all. With no ambient session-backed
 *     credential present, there is nothing for an attacker to ride.
 * Routes that still need protecting despite one of the above (rare) can force
 * the check by adding an `_csrf => true` default; routes that need to opt out
 * for any other reason can add `_csrf => false`.
 * Runs after PayloadParsingMiddleware (so the body is parsed) and
 * RoutingMiddleware (so route opt-out is known), before DispatchMiddleware. */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 40, after: 'RoutingMiddleware', before: 'DispatchMiddleware')]
class CsrfValidationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Controller $controller)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $csrf = new CsrfManager($this->controller->getContext());

        if (!$csrf->isEnabled()) {
            return $handler->handle($request);
        }

        // Safe methods are never checked.
        if (in_array(strtoupper($request->getMethod()), $csrf->safeMethods(), true)) {
            return $handler->handle($request);
        }

        $routeParams = $request->getAttribute('route_params');
        $forced = is_array($routeParams) && array_key_exists('_csrf', $routeParams) && $routeParams['_csrf'] === true;

        // Per-route opt-out: a route default of `_csrf => false`.
        if (!$forced && is_array($routeParams) && array_key_exists('_csrf', $routeParams) && $routeParams['_csrf'] === false) {
            return $handler->handle($request);
        }

        if (!$forced && $this->isExemptFromCsrf($request)) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debug('[CsrfValidationMiddleware] exempt ' . $request->getMethod() . ' ' . $request->getUri()->getPath() . ' (no ambient session credential)');
            }
            return $handler->handle($request);
        }

        $submitted = $this->extractToken($request, $csrf);

        if ($submitted === null || !$csrf->isValid($submitted)) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debug('[CsrfValidationMiddleware] rejected ' . $request->getMethod() . ' ' . $request->getUri()->getPath() . ' (token ' . ($submitted === null ? 'missing' : 'invalid') . ')');
            }
            $factory = new Psr17Factory();
            return $factory->createResponse(403)
                ->withHeader('X-Quiote-Csrf', 'failed')
                ->withBody($factory->createStream('CSRF token validation failed.'));
        }

        return $handler->handle($request);
    }

    /**
     * Whether this request falls outside the CSRF threat model: either it carries its own
     * credential (Authorization header) rather than relying on an ambient session cookie, or
     * it has no session cookie at all so there is no ambient credential to ride.
     */
    private function isExemptFromCsrf(ServerRequestInterface $request): bool
    {
        if ($request->hasHeader('Authorization')) {
            return true;
        }

        return !$this->hasSessionCookie($request);
    }

    /**
     * Whether the request carries the configured session cookie (set via session_name()
     * once SessionMiddleware has started storage for this request).
     */
    private function hasSessionCookie(ServerRequestInterface $request): bool
    {
        $cookies = $request->getCookieParams();
        if (!is_array($cookies) || empty($cookies)) {
            return false;
        }
        $name = session_name();
        return isset($cookies[$name]) && $cookies[$name] !== '';
    }

    /**
     * Extract the submitted token from the form field (parsed body) or header.
     */
    private function extractToken(ServerRequestInterface $request, CsrfManager $csrf): ?string
    {
        $field = $csrf->fieldName();
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && isset($parsed[$field]) && is_string($parsed[$field]) && $parsed[$field] !== '') {
            return $parsed[$field];
        }

        $header = $request->getHeaderLine($csrf->headerName());
        if ($header !== '') {
            return $header;
        }

        return null;
    }
}
