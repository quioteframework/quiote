<?php

namespace Quiote\Mcp\Compiler;

use Quiote\Controller\Controller;
use Quiote\Execution\HttpMethodMapper;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Validator\Compiler\JsonSchema\ActionInputSchemaResolver;

/**
 * Discovers `#[Route]` action classes that are also decorated with the SDK's
 * own `#[McpTool]` attribute -- "add one attribute to an existing action" is
 * the headline feature. Modeled
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
            $httpMethod = $this->resolvePrimaryHttpMethod($route->methods);

            $definitions[] = new ActionToolDefinition(
                $mcpTool->name ?? $route->name,
                $mcpTool->description,
                $route->name,
                $httpMethod,
                $mcpTool->outputSchema,
                (new ActionInputSchemaResolver())->resolveForAction(
                    $controller,
                    $action,
                    $route->module,
                    $route->action,
                    HttpMethodMapper::toActionMethod($httpMethod),
                ),
            );
        }

        return $definitions;
    }

    /**
     * Pick the HTTP method the synthetic MCP tool-call request should be
     * dispatched as. A route's `methods` array order reflects nothing about
     * which verb does the real work -- `#[Route(methods: ['GET', 'POST'])]`
     * (GET for the empty form, POST for the actual write) previously bound
     * the tool to `methods[0]` unconditionally, so a two-verb action's tool
     * would silently dispatch to the no-op read verb and never call
     * executeWrite(), without any error at all. Prefer the first verb that
     * {@see HttpMethodMapper} doesn't map to 'read' (POST/PUT/PATCH/DELETE/
     * a custom write-like token) -- an MCP tool call is an imperative "do
     * this", so the verb that performs work should win over one that just
     * renders an empty form. Falls back to the first declared method when
     * every verb maps to 'read' (a genuinely read-only tool).
     * @param      array<int, string> $methods
     */
    private function resolvePrimaryHttpMethod(array $methods): string
    {
        foreach ($methods as $method) {
            if (HttpMethodMapper::toActionMethod($method) !== 'read') {
                return $method;
            }
        }
        return $methods[0] ?? 'GET';
    }
}
