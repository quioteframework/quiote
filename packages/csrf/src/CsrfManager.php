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
