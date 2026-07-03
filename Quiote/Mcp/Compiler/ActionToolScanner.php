<?php

namespace Quiote\Mcp\Compiler;

use Quiote\Controller\Controller;
use Quiote\Routing\Compiler\AttributeRouteScanner;

/**
 * Discovers `#[Route]` action classes that are also decorated with the SDK's
 * own `#[McpTool]` attribute (docs/MCP_SERVER_PLAN.md §7, §6 item 1) --
 * "add one attribute to an existing action" is the headline feature. Modeled
 * on {@see AttributeRouteScanner}: reuses it to find every `#[Route]` action,
 * then resolves each one's class the same way {@see Controller::createActionInstance()}
 * does and inspects it for `#[McpTool]`.
 *
 * A no-op (empty result) when `mcp/sdk` isn't installed -- guarded the same
 * way the ORM adapters guard on their optional dependency.
 */
final class ActionToolScanner
{
    /**
     * @param iterable<string>|null $moduleDirs Defaults to {@see AttributeRouteScanner}'s own default.
     * @return list<ActionToolDefinition>
     */
    public function scan(Controller $controller, ?iterable $moduleDirs = null): array
    {
        if (!class_exists(\Mcp\Capability\Attribute\McpTool::class)) {
            return [];
        }

        $plan = (new AttributeRouteScanner())->scan($moduleDirs);

        $definitions = [];
        foreach ($plan->routes as $route) {
            try {
                $action = $controller->createActionInstance($route->module, $route->action);
            } catch (\Throwable) {
                continue;
            }

            $attributes = (new \ReflectionClass($action))->getAttributes(
                \Mcp\Capability\Attribute\McpTool::class,
                \ReflectionAttribute::IS_INSTANCEOF,
            );
            if (!$attributes) {
                continue;
            }

            $mcpTool = $attributes[0]->newInstance();
            $httpMethod = $route->methods[0] ?? 'GET';

            $definitions[] = new ActionToolDefinition(
                $mcpTool->name ?? $route->name,
                $mcpTool->description,
                $route->name,
                $httpMethod,
                $mcpTool->outputSchema,
            );
        }

        return $definitions;
    }
}
