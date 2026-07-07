<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpActionTool\Actions;

use Mcp\Capability\Attribute\McpTool;
use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

/**
 * Regression fixture for ActionToolScanner::resolvePrimaryHttpMethod():
 * a route declaring GET (empty form, no-op) before POST (the verb that does
 * the real work) -- same shape as the app's real CreatePostAction, which
 * silently bound its MCP tool to the no-op GET verb before the fix. GET
 * first here specifically to prove `methods[0]` is no longer trusted.
 */
#[Route('/mcp-action-tool-test/multi-verb', name: 'mcp_action_tool_test.multi_verb', methods: ['GET', 'POST'], outputType: 'html')]
#[McpTool(name: 'multi_verb_via_action', description: 'Exercises multi-verb primary-method resolution')]
class MultiVerbAction extends Action
{
    public function executeRead()
    {
        return 'Success';
    }

    public function executeWrite()
    {
        return 'Success';
    }
}
