<?php

use Quiote\Context;
use Quiote\Mcp\Compiler\ActionToolScanner;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The actions-as-tools discovery pass (docs/MCP_SERVER_PLAN.md §7): finds
 * `#[Route]` action classes additionally decorated with the SDK's own
 * `#[McpTool]` attribute. Fixture: tests/sandbox/app/Modules/McpActionTool/Actions/GreetAction.php.
 * Pure reflection -- no route matching involved, so this is safe to run
 * against any context's Controller regardless of that context's routing.
 */
final class ActionToolScannerTest extends PhpUnitTestCase
{
    public function testDiscoversAnActionCarryingBothRouteAndMcpTool(): void
    {
        $controller = Context::getInstance('mcp-action-tool-test')->getController();
        $definitions = (new ActionToolScanner())->scan($controller);

        $byName = [];
        foreach ($definitions as $definition) {
            $byName[$definition->toolName] = $definition;
        }

        $this->assertArrayHasKey('greet_via_action', $byName);
        $tool = $byName['greet_via_action'];
        $this->assertSame('Greets someone via the actions-as-tools bridge', $tool->description);
        $this->assertSame('mcp_action_tool_test.greet', $tool->routeName);
        $this->assertSame('GET', $tool->httpMethod);
        $this->assertNull($tool->outputSchema);
    }

    public function testIgnoresRouteActionsWithoutMcpTool(): void
    {
        $controller = Context::getInstance('mcp-action-tool-test')->getController();
        $definitions = (new ActionToolScanner())->scan($controller);

        $routeNames = array_map(static fn($d) => $d->routeName, $definitions);
        $this->assertNotContains('attr_routing.list', $routeNames, 'AttrRouting fixtures carry #[Route] but not #[McpTool]');
    }

    public function testReturnsEmptyForAModuleDirWithNoRoutedActionsAtAll(): void
    {
        $controller = Context::getInstance('mcp-action-tool-test')->getController();
        $definitions = (new ActionToolScanner())->scan($controller, [sys_get_temp_dir()]);

        $this->assertSame([], $definitions);
    }
}
