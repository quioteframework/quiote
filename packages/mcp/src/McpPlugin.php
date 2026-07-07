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

        $registrar->command(McpServeCommand::class);
        $registrar->command(McpWarmupCommand::class);

        $registrar->service(
            McpAuthenticatorInterface::class,
            fn() => new StaticTokenAuthenticator(Config::getNullableString('mcp.auth_token')),
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

            if (Config::getString('mcp.auth', 'bearer') !== 'none') {
                $registrar->middleware(
                    McpAuthMiddleware::class,
                    fn() => new McpAuthMiddleware($contextName),
                    before: McpEndpointMiddleware::class,
                );
            }
        }
    }
}
