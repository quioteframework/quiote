<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Mcp\McpPlugin;
use Quiote\Mcp\Middleware\McpAuthMiddleware;
use Quiote\Mcp\Middleware\McpEndpointMiddleware;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Plugin\PluginManager;

/**
 * McpPlugin::register() -- the settings it publishes are all opt-in-safe, and
 * the HTTP middleware is only spliced into the pipeline when
 * `mcp.transports` actually asks for "http".
 */
final class McpPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        MiddlewareCatalog::reset();
        Config::remove('mcp.enabled');
        Config::remove('mcp.transports');
        Config::remove('mcp.path');
        Config::remove('mcp.protocol_version');
        Config::remove('mcp.stateless');
        Config::remove('mcp.server_name');
        Config::remove('mcp.server_version');
        Config::remove('mcp.auth');
        Config::remove('mcp.auth_token');
        Config::remove('mcp.expose_actions');
        Config::remove('mcp.module_dirs');
    }

    public function testDefaultsAreOptInSafe(): void
    {
        PluginManager::add(new McpPlugin());
        PluginManager::bootFromConfig();

        $this->assertFalse(Config::getBool('mcp.enabled'));
        $this->assertSame(['stdio'], Config::getArray('mcp.transports'));
        $this->assertArrayNotHasKey(McpEndpointMiddleware::class, MiddlewareCatalog::getRegistered());
        $this->assertArrayNotHasKey(McpAuthMiddleware::class, MiddlewareCatalog::getRegistered());
    }

    public function testHttpTransportRegistersTheEndpointAndAuthMiddleware(): void
    {
        Config::set('mcp.transports', ['http', 'stdio'], true);

        PluginManager::add(new McpPlugin());
        PluginManager::bootFromConfig();

        $registered = MiddlewareCatalog::getRegistered();
        $this->assertArrayHasKey(McpEndpointMiddleware::class, $registered);
        $this->assertArrayHasKey(McpAuthMiddleware::class, $registered);
        $this->assertSame(McpEndpointMiddleware::class, $registered[McpAuthMiddleware::class]['before'], 'auth must anchor before the endpoint so it runs first');
    }

    public function testAuthNoneSkipsTheAuthMiddlewareButKeepsTheEndpoint(): void
    {
        Config::set('mcp.transports', ['http'], true);
        Config::set('mcp.auth', 'none', true);

        PluginManager::add(new McpPlugin());
        PluginManager::bootFromConfig();

        $registered = MiddlewareCatalog::getRegistered();
        $this->assertArrayHasKey(McpEndpointMiddleware::class, $registered);
        $this->assertArrayNotHasKey(McpAuthMiddleware::class, $registered);
    }

    public function testStdioOnlyDoesNotRegisterAnyMiddleware(): void
    {
        Config::set('mcp.transports', ['stdio'], true);

        PluginManager::add(new McpPlugin());
        PluginManager::bootFromConfig();

        $registered = MiddlewareCatalog::getRegistered();
        $this->assertArrayNotHasKey(McpEndpointMiddleware::class, $registered);
        $this->assertArrayNotHasKey(McpAuthMiddleware::class, $registered);
    }

    public function testMcpServeCommandIsAlwaysContributed(): void
    {
        PluginManager::add(new McpPlugin());
        PluginManager::bootFromConfig();

        $this->assertContains(\Quiote\Mcp\Console\McpServeCommand::class, PluginManager::contributedCommands());
    }

    public function testAuthenticatorServiceDefaultIsPublishedToContainers(): void
    {
        PluginManager::add(new McpPlugin());
        PluginManager::bootFromConfig();

        $container = new \Quiote\DI\Container();
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(
            \Quiote\Mcp\Auth\StaticTokenAuthenticator::class,
            $container->get(\Quiote\Mcp\Auth\McpAuthenticatorInterface::class),
        );
    }
}
