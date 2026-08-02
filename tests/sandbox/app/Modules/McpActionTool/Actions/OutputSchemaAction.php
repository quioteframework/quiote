<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpActionTool\Actions;

use Mcp\Capability\Attribute\McpTool;
use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

/**
 * Fixture for McpServer::buildToolOutputSchema()'s `additionalProperties`
 * handling: an explicit, author-supplied outputSchema whose
 * `additionalProperties` is a nested schema (not a plain bool), exercised
 * end to end by McpServerActionToolIntegrationTest.
 */
#[Route('/mcp-action-tool-test/output-schema', name: 'mcp_action_tool_test.output_schema', methods: ['GET'], outputType: 'html')]
#[McpTool(
    name: 'output_schema_via_action',
    description: 'Exercises a nested-schema additionalProperties on outputSchema',
    outputSchema: [
        'type' => 'object',
        'properties' => ['label' => ['type' => 'string']],
        'additionalProperties' => ['type' => 'string'],
    ],
)]
class OutputSchemaAction extends Action
{
    public function executeRead(): string
    {
        return 'Success';
    }
}
