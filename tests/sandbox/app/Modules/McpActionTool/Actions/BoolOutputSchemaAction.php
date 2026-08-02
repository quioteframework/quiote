<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpActionTool\Actions;

use Mcp\Capability\Attribute\McpTool;
use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

/**
 * Regression fixture for McpServer::buildToolOutputSchema()'s
 * `additionalProperties` handling: a plain bool must keep passing through
 * unchanged alongside {@see OutputSchemaAction}'s nested-schema case.
 */
#[Route('/mcp-action-tool-test/bool-output-schema', name: 'mcp_action_tool_test.bool_output_schema', methods: ['GET'], outputType: 'html')]
#[McpTool(
    name: 'bool_output_schema_via_action',
    description: 'Exercises a plain bool additionalProperties on outputSchema',
    outputSchema: [
        'type' => 'object',
        'properties' => ['label' => ['type' => 'string']],
        'additionalProperties' => false,
    ],
)]
class BoolOutputSchemaAction extends Action
{
    public function executeRead(): string
    {
        return 'Success';
    }
}
