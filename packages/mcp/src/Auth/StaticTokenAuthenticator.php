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
    public function __construct(private readonly ?string $expectedToken)
    {
    }

    public function authenticate(string $token): bool
    {
        if ($this->expectedToken === null || $this->expectedToken === '' || $token === '') {
            return false;
        }

        return hash_equals($this->expectedToken, $token);
    }
}
