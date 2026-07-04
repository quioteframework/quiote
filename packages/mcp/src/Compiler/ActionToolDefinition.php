<?php

namespace Quiote\Mcp\Compiler;

/**
 * Format-independent description of one action exposed as an MCP tool
 * (docs/MCP_SERVER_PLAN.md §7) -- what {@see ActionToolScanner} discovers,
 * consumed by {@see \Quiote\Mcp\McpServer} to build the actual SDK
 * registration. Deliberately carries no `mcp/sdk` types (mirrors
 * `Quiote\Routing\Compiler\RouteDefinition`'s format-independence).
 */
final class ActionToolDefinition
{
    /**
     * @param array<string, mixed>|null $outputSchema JSON Schema for the tool's
     *        output, from `#[McpTool(outputSchema: ...)]` if the action author
     *        supplied one.
     * @param array<string, mixed>|null $inputSchema JSON Schema derived from the
     *        action's declared validators ({@see ValidatorSchemaMapper}), or null
     *        when none could be derived (caller falls back to a permissive schema).
     */
    public function __construct(
        public readonly string $toolName,
        public readonly ?string $description,
        public readonly string $routeName,
        public readonly string $httpMethod,
        public readonly ?array $outputSchema,
        public readonly ?array $inputSchema = null,
    ) {
    }
}
