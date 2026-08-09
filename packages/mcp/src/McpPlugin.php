<?php

namespace Quiote\Mcp;

use Quiote\Config\Config;
use Quiote\Mcp\Auth\McpAuthenticatorInterface;
use Quiote\Mcp\Auth\StaticTokenAuthenticator;
use Quiote\Mcp\Console\McpServeCommand;
use Quiote\Mcp\Console\McpWarmupCommand;
use Quiote\Mcp\Middleware\McpAuthMiddleware;
use Quiote\Mcp\Middleware\McpEndpointMiddleware;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;
use Quiote\DI\Container;

/**
 * Opt-in entry point for the MCP server capability. Adding this class to the
 * `plugins` config key publishes the `mcp.*` setting
 * defaults (all opt-in-safe: `mcp.enabled` defaults to `false`) and registers
 * `mcp:serve`. When the adapters are extracted into a standalone composer
 * package this plugin (and `Quiote\Mcp\*`) move to `quioteframework/quiote-mcp`
 * unchanged, mirroring the ORM adapter plugins.
 */
#[PluginAttribute(name: 'quiote/mcp')]
final class McpPlugin implements PluginInterface
{
    /**
     * Publishes the `mcp.*` config defaults and wires the MCP capability.
     *
     * Registers the `mcp:serve` and `mcp:warmup` commands and the singleton
     * {@see McpAuthenticatorInterface} binding backed by
     * {@see StaticTokenAuthenticator}. When `mcp.transports` includes `http`,
     * also splices {@see McpEndpointMiddleware} into the pipeline before
     * `SecurityMiddleware`, followed by {@see McpAuthMiddleware} before it —
     * the latter only when `mcp.auth` is neither `none` (no auth) nor `oauth2`
     * (enforced inside the SDK's own transport middleware instead).
     */
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('mcp.enabled', false);
        $registrar->configDefault('mcp.transports', ['stdio']);
        $registrar->configDefault('mcp.path', '/mcp');
        $registrar->configDefault('mcp.protocol_version', '2025-11-25');
        $registrar->configDefault('mcp.stateless', true);
        $registrar->configDefault('mcp.server_name', 'quiote-app');
        $registrar->configDefault('mcp.server_version', '1.0.0');
        $registrar->configDefault('mcp.auth', 'bearer');
        $registrar->configDefault('mcp.auth_token', null);
        $registrar->configDefault('mcp.expose_actions', false);
        $registrar->configDefault('mcp.module_dirs', []);
        $registrar->configDefault('mcp.discover_attributes', false);
        $registrar->configDefault('mcp.discovery_cache', true);
        $registrar->configDefault('mcp.oauth.issuer', null);
        $registrar->configDefault('mcp.oauth.audience', null);
        $registrar->configDefault('mcp.oauth.jwks_uri', null);
        $registrar->configDefault('mcp.oauth.scopes_supported', []);
        $registrar->configDefault('mcp.oauth.cache_ttl', 3600);

        $registrar->command(McpServeCommand::class);
        $registrar->command(McpWarmupCommand::class);

        $registrar->service(
            McpAuthenticatorInterface::class,
            fn() => new StaticTokenAuthenticator(Config::getNullableString('mcp.auth_token')),
            Container::SCOPE_SINGLETON,
        );

        $transports = Config::getArray('mcp.transports', ['stdio']);
        if (in_array('http', $transports, true)) {
            $contextName = Config::getString('core.default_context', 'web');

            // McpAuthMiddleware anchors "before: McpEndpointMiddleware::class" -- the
            // endpoint middleware must already be registered (and thus spliced into
            // the pipeline first, see MiddlewarePipeline::insertRegistered()) for that
            // anchor to resolve, so registration order here matters.
            $registrar->middleware(
                McpEndpointMiddleware::class,
                fn() => new McpEndpointMiddleware($contextName),
                before: SecurityMiddleware::class,
            );

            // 'none' has no auth at all; 'oauth2' is enforced inside the SDK's own
            // StreamableHttpTransport middleware stack (see McpServer::handleHttp()),
            // not this framework-level PSR-15 pipeline -- registering McpAuthMiddleware
            // too would just add a second, static-token-only check in front of it.
            $mcpAuth = Config::getString('mcp.auth', 'bearer');
            if ($mcpAuth !== 'none' && $mcpAuth !== 'oauth2') {
                $registrar->middleware(
                    McpAuthMiddleware::class,
                    fn() => new McpAuthMiddleware($contextName),
                    before: McpEndpointMiddleware::class,
                );
            }
        }
    }
}
