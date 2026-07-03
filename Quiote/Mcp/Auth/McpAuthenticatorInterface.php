<?php

namespace Quiote\Mcp\Auth;

/**
 * Validates the bearer token presented to the MCP HTTP endpoint (docs/MCP_SERVER_PLAN.md
 * §10, Phase A). Bind a different implementation via
 * `PluginRegistrar::service(McpAuthenticatorInterface::class, ...)` to delegate to an
 * app's own credential store instead of the static-token default
 * ({@see StaticTokenAuthenticator}).
 */
interface McpAuthenticatorInterface
{
    /** @return bool whether $token is valid */
    public function authenticate(string $token): bool;
}
