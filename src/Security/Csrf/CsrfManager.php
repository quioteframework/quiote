<?php

namespace Agavi\Security\Csrf;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

/**
 * Application-facing CSRF helper.
 *
 * Wraps symfony/security-csrf's CsrfTokenManager (backed by the session via
 * AgaviSessionTokenStorage) and exposes the framework's CSRF configuration
 * (enabled flag, token id, form field / header names, safe HTTP methods).
 *
 * Token values are BREACH-mitigated/randomized per call by the underlying
 * Symfony manager; comparison is constant-time.
 *
 * @package    agavi
 * @subpackage security
 */
final class CsrfManager
{
    private CsrfTokenManager $manager;

    public function __construct(private readonly AgaviContext $context)
    {
        $this->manager = new CsrfTokenManager(
            null, // default UriSafeTokenGenerator (random_bytes based)
            new AgaviSessionTokenStorage($context)
        );
    }

    public function isEnabled(): bool
    {
        return (bool) AgaviConfig::get('core.csrf.enabled', true);
    }

    public function tokenId(): string
    {
        return (string) AgaviConfig::get('core.csrf.token_id', 'agavi_csrf');
    }

    public function fieldName(): string
    {
        return (string) AgaviConfig::get('core.csrf.field_name', '_csrf_token');
    }

    public function headerName(): string
    {
        return (string) AgaviConfig::get('core.csrf.header_name', 'X-CSRF-Token');
    }

    /**
     * HTTP methods that are NOT CSRF-checked (safe / idempotent by convention).
     *
     * @return string[] Upper-cased method names.
     */
    public function safeMethods(): array
    {
        $methods = AgaviConfig::get('core.csrf.safe_methods', ['GET', 'HEAD', 'OPTIONS', 'TRACE']);
        if (!is_array($methods)) {
            $methods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
        }
        return array_map(static fn($m) => strtoupper((string) $m), $methods);
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
