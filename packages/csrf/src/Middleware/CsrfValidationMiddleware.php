<?php

namespace Quiote\Security\Csrf\Middleware;

use Quiote\Controller\Controller;
use Quiote\Security\Csrf\CsrfManager;
use Quiote\Http\Psr17;
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
 *   - Requests an authenticator already resolved from a caller-supplied
 *     credential (JWT, API key, OAuth2 bearer token), signalled by the
 *     `auth.stateless`/`auth.sessionless` request attributes. Such a caller's
 *     identity does not come from an ambient cookie, so it is not forgeable
 *     cross-site. Note this is deliberately NOT "an Authorization header is
 *     present": that header can be attached alongside a session cookie, so
 *     presence alone proved nothing and made the exemption a bypass.
 *   - Requests with no session cookie at all AND no foreign `Origin`. With no
 *     ambient session-backed credential present there is nothing for an
 *     attacker to ride, but that is only true of the request itself -- a login
 *     POST also arrives without a session, and exempting it on that basis made
 *     login CSRF work. So the sessionless exemption additionally requires that
 *     the request is not a browser request from another origin; see
 *     {@see CsrfManager::isCrossOriginBrowserRequest()}. Non-browser callers
 *     send no `Origin` and stay exempt. The cookie name comes from the
 *     configured SessionManager via {@see CsrfManager::hasSessionCookie()},
 *     never from ext/session's session_name() -- the modern session mechanism
 *     does not use ext/session, so session_name() named a cookie Quiote never
 *     sets and this exemption matched every request.
 * Routes that still need protecting despite one of the above (rare) can force
 * the check by adding an `_csrf => true` default; routes that need to opt out
 * for any other reason can add `_csrf => false`.
 * Runs after PayloadParsingMiddleware (so the body is parsed) and
 * RoutingMiddleware (so route opt-out is known), before DispatchMiddleware. */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 40, after: 'RoutingMiddleware', before: 'DispatchMiddleware')]
class CsrfValidationMiddleware implements MiddlewareInterface
{
    /** One warning per process, not per request; see isExemptFromCsrf(). */
    private static bool $warnedAboutMissingSession = false;

    public function __construct(private readonly Controller $controller)
    {
    }

    /** Test isolation: re-arm the once-per-process missing-session warning. */
    public static function resetWarnings(): void
    {
        self::$warnedAboutMissingSession = false;
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

        if (!$forced && $this->isExemptFromCsrf($request, $csrf)) {
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
            $factory = Psr17::factory();
            return $factory->createResponse(403)
                ->withHeader('X-Quiote-Csrf', 'failed')
                ->withBody($factory->createStream('CSRF token validation failed.'));
        }

        return $handler->handle($request);
    }

    /**
     * Whether this request falls outside the CSRF threat model: either it was
     * authenticated by its own non-ambient credential rather than by a session
     * cookie, or it has no session cookie at all so there is no ambient
     * credential to ride.
     */
    private function isExemptFromCsrf(ServerRequestInterface $request, CsrfManager $csrf): bool
    {
        if ($this->isStatelesslyAuthenticated($request)) {
            return true;
        }

        if ($csrf->hasSessionCookie($request)) {
            return false;
        }

        // No session cookie -- but "no session yet" is precisely the state a
        // login POST arrives in, and exempting it made login CSRF work: an
        // attacker's page posts their own credentials to /login, the victim's
        // browser has no session to ride so the check is skipped, and the victim
        // ends up authenticated as the attacker, with everything they then do
        // recorded in the attacker's account.
        //
        // Requiring a token instead is not an option here -- there is no session
        // to have stored one in, so a genuine first-time visitor would be
        // rejected too. What separates the two callers is the origin: the
        // legitimate POST comes from a page this application served, the
        // attacker's does not. Only browsers send Origin, and only browsers
        // attach ambient credentials, so keying off it costs non-browser clients
        // nothing (see isCrossOriginBrowserRequest()).
        if ($csrf->isCrossOriginBrowserRequest($request)) {
            return false;
        }

        // Sessionless and same-origin (or not a browser at all): genuinely
        // nothing to ride. But if the application has no session mechanism at
        // all then EVERY request looks like this and CSRF is effectively off,
        // which an operator who enabled it deserves to hear about. Once per
        // process, not per request.
        if (!self::$warnedAboutMissingSession && !$csrf->hasSessionMechanism()) {
            self::$warnedAboutMissingSession = true;
            \Quiote\Logging\Log::for($this)->warning(
                '[CsrfValidationMiddleware] CSRF is enabled (core.csrf.enabled) but this context has no '
                . 'session factory slot configured, so no request ever carries a session cookie and every '
                . 'request is exempt -- CSRF is not protecting anything. Configure a "session" factory, or '
                . 'set core.csrf.enabled to false to make the intent explicit.'
            );
        }

        return true;
    }

    /**
     * Whether the request was authenticated by a credential the caller supplied
     * itself (bearer/JWT/API key) rather than by an ambient session cookie.
     *
     * Keyed off the request attributes the auth packages set once they have
     * actually validated such a credential -- NOT off the mere presence of an
     * `Authorization` header. A header is trivially attachable alongside a
     * session cookie (a permissive CORS allowlist is enough), and treating its
     * presence as proof of non-ambient authentication turned the exemption into
     * a CSRF bypass: `Authorization: Bearer <garbage>` plus a valid session
     * cookie authenticated via the session and skipped the token check.
     */
    private function isStatelesslyAuthenticated(ServerRequestInterface $request): bool
    {
        return $request->getAttribute('auth.stateless') === true
            || $request->getAttribute('auth.sessionless') === true
            || $request->getAttribute('jwt.skip_session') === true;
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
