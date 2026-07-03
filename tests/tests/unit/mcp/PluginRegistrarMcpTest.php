<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Mcp\McpCatalog;
use Quiote\Plugin\PluginRegistrar;

/**
 * PluginRegistrar::mcpTool()/mcpResource()/mcpPrompt() are thin routers onto
 * McpCatalog (docs/MCP_SERVER_PLAN.md §6.2) -- verify the [class, method]
 * pairing and the "no explicit method => __invoke" default.
 */
final class PluginRegistrarMcpTest extends TestCase
{
    #[Before]
    #[After]
    public function resetCatalog(): void
    {
        McpCatalog::reset();
    }

    private function registrar(): PluginRegistrar
    {
        return new PluginRegistrar('test/plugin');
    }

    public function testMcpToolWithExplicitMethodRegistersAnArrayHandler(): void
    {
        $this->registrar()->mcpTool('App\\Mcp\\WeatherTool', 'forecast', 'weather_forecast', 'returns a forecast');

        $tools = McpCatalog::tools();
        $this->assertCount(1, $tools);
        $this->assertSame(['App\\Mcp\\WeatherTool', 'forecast'], $tools[0]['handler']);
        $this->assertSame('weather_forecast', $tools[0]['name']);
        $this->assertSame('returns a forecast', $tools[0]['description']);
    }

    public function testMcpToolWithoutMethodDefaultsToInvoke(): void
    {
        $this->registrar()->mcpTool('App\\Mcp\\WeatherTool');

        $tools = McpCatalog::tools();
        $this->assertSame('App\\Mcp\\WeatherTool', $tools[0]['handler']);
    }

    public function testMcpResourceRoutesToCatalog(): void
    {
        $this->registrar()->mcpResource('App\\Mcp\\WidgetResource', 'app://widgets', 'list', 'widgets');

        $resources = McpCatalog::resources();
        $this->assertSame(['App\\Mcp\\WidgetResource', 'list'], $resources[0]['handler']);
        $this->assertSame('app://widgets', $resources[0]['uri']);
        $this->assertSame('widgets', $resources[0]['name']);
    }

    public function testMcpPromptRoutesToCatalog(): void
    {
        $this->registrar()->mcpPrompt('App\\Mcp\\GreetingPrompt', 'build', 'greeting');

        $prompts = McpCatalog::prompts();
        $this->assertSame(['App\\Mcp\\GreetingPrompt', 'build'], $prompts[0]['handler']);
        $this->assertSame('greeting', $prompts[0]['name']);
    }

    public function testFluentMethodsReturnSelfForChaining(): void
    {
        $registrar = $this->registrar();
        $this->assertSame($registrar, $registrar->mcpTool('App\\Mcp\\WeatherTool'));
        $this->assertSame($registrar, $registrar->mcpResource('App\\Mcp\\WidgetResource', 'app://widgets'));
        $this->assertSame($registrar, $registrar->mcpPrompt('App\\Mcp\\GreetingPrompt'));
    }
}
