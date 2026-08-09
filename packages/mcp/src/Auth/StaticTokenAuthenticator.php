<?php

namespace Quiote\Mcp\Auth;

/**
 * The default {@see McpAuthenticatorInterface}: a single shared secret from the
 * `mcp.auth_token` setting. A null/empty configured token always rejects --
 * there is no "auth disabled by an empty token" footgun; use `mcp.auth = 'none'`
 * to actually disable auth.
 */
final class StaticTokenAuthenticator implements McpAuthenticatorInterface
{
    public function __construct(#[\SensitiveParameter] private readonly ?string $expectedToken)
    {
    }

    /**
     * Compares $token against the configured secret in constant time.
     *
     * Returns false whenever either the configured token or the presented one
     * is null or empty, so a missing `mcp.auth_token` denies every request
     * rather than accepting any.
     */
    public function authenticate(#[\SensitiveParameter] string $token): bool
    {
        if ($this->expectedToken === null || $this->expectedToken === '' || $token === '') {
            return false;
        }

        return hash_equals($this->expectedToken, $token);
    }
}
