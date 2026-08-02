<?php

namespace Quiote\Security\Csrf;

use Quiote\Context;
use Quiote\Config\Config;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

/**
 * Application-facing CSRF helper.
 * Wraps symfony/security-csrf's CsrfTokenManager (backed by the session via
 * SessionTokenStorage) and exposes the framework's CSRF configuration
 * (enabled flag, token id, form field / header names, safe HTTP methods).
 * Token values are BREACH-mitigated/randomized per call by the underlying
 * Symfony manager; comparison is constant-time. */
final readonly class CsrfManager
{
    private CsrfTokenManager $manager;

    public function __construct(private Context $context)
    {
        $this->manager = new CsrfTokenManager(
            null, // default UriSafeTokenGenerator (random_bytes based)
            new SessionTokenStorage($context)
        );
    }

    public function isEnabled(): bool
    {
        return Config::getBool('core.csrf.enabled', true);
    }

    public function tokenId(): string
    {
        return Config::getString('core.csrf.token_id', 'quiote_csrf');
    }

    public function fieldName(): string
    {
        return Config::getString('core.csrf.field_name', '_csrf_token');
    }

    public function headerName(): string
    {
        return Config::getString('core.csrf.header_name', 'X-CSRF-Token');
    }

    /**
     * Name of the readable (non-HttpOnly) cookie used to deliver the token to
     * same-origin SPA/JS clients that don't get a server-rendered meta tag.
     */
    public function cookieName(): string
    {
        return Config::getString('core.csrf.cookie_name', 'XSRF-TOKEN');
    }

    /**
     * Name of the cookie that carries the session id for this application.
     *
     * Resolved from the configured {@see \Quiote\Session\SessionManager} (whose
     * `cookie_name` defaults to `QSID`), NOT from ext/session's session_name():
     * the modern session mechanism never touches ext/session, so session_name()
     * answers `PHPSESSID` — a cookie Quiote never sets. Falling back to
     * session_name() is still right when no session factory slot is configured,
     * because that is the legacy `storage`/native-`$_SESSION` path where
     * ext/session genuinely owns the cookie.
     */
    public function sessionCookieName(): string
    {
        $manager = $this->context->getSessionManager();
        if ($manager !== null) {
            return $manager->getCookieName();
        }

        $native = session_name();

        return $native === false ? 'PHPSESSID' : $native;
    }

    /**
     * Whether $request carries this application's session cookie -- i.e. whether
     * there is an ambient, automatically-attached credential for a cross-site
     * attacker to ride. Both the validation and the token-delivery middleware
     * key off this, so they must agree on the answer.
     */
    public function hasSessionCookie(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        $cookies = $request->getCookieParams();
        if ($cookies === []) {
            return false;
        }

        $name = $this->sessionCookieName();
        $value = $cookies[$name] ?? null;

        return is_string($value) && $value !== '';
    }

    /**
     * Whether this application has a session mechanism at all. When it does not,
     * every request looks sessionless to {@see hasSessionCookie()} and CSRF
     * validation exempts all of them -- which is correct on its own terms (no
     * ambient credential exists) but is a misconfiguration worth surfacing when
     * CSRF is otherwise enabled.
     */
    public function hasSessionMechanism(): bool
    {
        return $this->context->getSessionManager() !== null;
    }

    /**
     * Origins accepted as this application's own, beyond the request's own host.
     *
     * Only needed where the host a browser used and the host this process sees
     * genuinely differ -- a proxy that rewrites `Host`, or a deliberately
     * split-origin deployment. Each entry is compared whole
     * (`https://app.example.com`), not by suffix, so a value here cannot widen
     * into sibling hosts the way a bare-domain match would.
     *
     * @return string[]
     */
    public function trustedOrigins(): array
    {
        return Config::getStringList('core.csrf.trusted_origins', []);
    }

    /**
     * Whether $request was initiated by a browser from some *other* origin.
     *
     * This is what distinguishes the two callers that both arrive without a
     * session cookie: a legitimate first-time visitor posting a login form from
     * this application's own page, and an attacker's page posting the same form
     * cross-site. Both lack the ambient credential the token check keys off, so
     * only the origin tells them apart.
     *
     * An absent `Origin` means "not a browser" -- curl, a server-to-server
     * caller, an SDK -- and returns false. That is not a loophole an attacker
     * can take: the header is attached by the browser and is not settable from
     * page script, so a cross-site request cannot suppress it. A literal `null`
     * origin (sandboxed iframe, opaque origin) is the opposite case and counts
     * as foreign.
     *
     * The comparison is host-only, deliberately, and not scheme+host+port. This
     * runs behind TLS-terminating proxies where the request's own scheme is
     * `http` and its port is an internal one, while the browser's `Origin` says
     * `https` on 443; comparing those would reject legitimate same-site
     * requests on every such deployment. What that concedes is an attacker who
     * already controls another port or the plaintext scheme on this very
     * hostname -- a position from which the session cookie is reachable
     * regardless, so the token check was never what stood in the way.
     */
    public function isCrossOriginBrowserRequest(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return false;
        }

        if (strcasecmp($origin, 'null') === 0) {
            return true;
        }

        foreach ($this->trustedOrigins() as $trusted) {
            if ($trusted !== '' && strcasecmp(rtrim($trusted, '/'), rtrim($origin, '/')) === 0) {
                return false;
            }
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = $request->getUri()->getHost();
        if (!is_string($originHost) || $originHost === '' || $requestHost === '') {
            // An Origin that will not parse, or no host to compare it against.
            // Neither can establish same-origin, and the safe reading of "cannot
            // establish" is "did not".
            return true;
        }

        return strcasecmp($originHost, $requestHost) !== 0;
    }

    /**
     * HTTP methods that are NOT CSRF-checked (safe / idempotent by convention).
     * @return string[] Upper-cased method names.
     */
    public function safeMethods(): array
    {
        $methods = Config::getArray('core.csrf.safe_methods', ['GET', 'HEAD', 'OPTIONS', 'TRACE']);
        return array_map(static fn($m) => strtoupper(is_scalar($m) ? (string) $m : ''), $methods);
    }

    /**
     * Return the current token value, generating and persisting one if needed.
     */
    public function getTokenValue(): string
    {
        return $this->manager->getToken($this->tokenId())->getValue();
    }

    /**
     * Validate a submitted token value (constant-time).
     */
    public function isValid(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        return $this->manager->isTokenValid(new CsrfToken($this->tokenId(), $value));
    }

    /**
     * Discard the current token (e.g. on logout / full session reset).
     */
    public function removeToken(): void
    {
        $this->manager->removeToken($this->tokenId());
    }
}
