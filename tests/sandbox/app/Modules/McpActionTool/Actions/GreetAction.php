<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpActionTool\Actions;

use Mcp\Capability\Attribute\McpTool;
use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

/**
 * Test-only fixture for the actions-as-tools bridge (docs/MCP_SERVER_PLAN.md
 * §7): an ordinary #[Route] action additionally carrying the SDK's own
 * #[McpTool] attribute, exercised by ActionToolScannerTest/ActionToolAdapterTest
 * via the "mcp-action-tool-test" context (Config/factories.xml), whose routing
 * (Quiote\Routing\AttributeRouting) scans only #[Route]-attributed classes --
 * unlike SandboxRouting's committed Routes::build(), which also carries an
 * unrelated pre-existing malformed legacy route (test_ticket_444_sample2) that
 * breaks real request dispatch for any path.
 */
#[Route('/mcp-action-tool-test/greet/{name}', name: 'mcp_action_tool_test.greet', methods: ['GET'], outputType: 'html')]
#[McpTool(name: 'greet_via_action', description: 'Greets someone via the actions-as-tools bridge')]
class GreetAction extends Action
{
    public function executeRead()
    {
        return 'Success';
    }
}
