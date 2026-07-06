<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Mcp\McpConfig;

final class McpConfigTest extends TestCase
{
    private const KEYS = [
        'mcp.enabled', 'mcp.transports', 'mcp.path', 'mcp.protocol_version',
        'mcp.stateless', 'mcp.server_name', 'mcp.server_version', 'mcp.auth',
        'mcp.expose_actions', 'mcp.module_dirs', 'mcp.discover_attributes',
        'mcp.discovery_cache', 'core.app_name',
    ];

    #[Before]
    #[After]
    public function resetConfig(): void
    {
        foreach (self::KEYS as $key) {
            Config::remove($key);
        }
    }

    public function testDefaultsAreOptInSafe(): void
    {
        $config = McpConfig::fromConfig();

        $this->assertFalse($config->enabled);
        $this->assertSame(['stdio'], $config->transports);
        $this->assertSame('/mcp', $config->path);
        $this->assertSame('2025-11-25', $config->protocolVersion);
        $this->assertTrue($config->stateless);
        $this->assertSame('quiote-app', $config->serverName);
        $this->assertSame('1.0.0', $config->serverVersion);
        $this->assertSame('bearer', $config->auth);
        $this->assertFalse($config->exposeActions);
        $this->assertSame([], $config->moduleDirs);
        $this->assertFalse($config->discoverAttributes);
        $this->assertTrue($config->discoveryCache);
    }

    public function testServerNameFallsBackToCoreAppName(): void
    {
        Config::set('core.app_name', 'My Cool App', true);

        $this->assertSame('My Cool App', McpConfig::fromConfig()->serverName);
    }

    public function testExplicitServerNameWinsOverCoreAppName(): void
    {
        Config::set('core.app_name', 'My Cool App', true);
        Config::set('mcp.server_name', 'explicit-name', true);

        $this->assertSame('explicit-name', McpConfig::fromConfig()->serverName);
    }

    public function testOverridesAreHonored(): void
    {
        Config::set('mcp.enabled', true, true);
        Config::set('mcp.transports', ['http', 'stdio'], true);
        Config::set('mcp.expose_actions', true, true);
        Config::set('mcp.discover_attributes', true, true);
        Config::set('mcp.discovery_cache', false, true);

        $config = McpConfig::fromConfig();

        $this->assertTrue($config->enabled);
        $this->assertSame(['http', 'stdio'], $config->transports);
        $this->assertTrue($config->exposeActions);
        $this->assertTrue($config->discoverAttributes);
        $this->assertFalse($config->discoveryCache);
    }
}
