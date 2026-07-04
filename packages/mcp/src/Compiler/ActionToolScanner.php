<?php

namespace Quiote\Mcp\Compiler;

use Quiote\Config\Config;
use Quiote\Controller\Controller;
use Quiote\Execution\HttpMethodMapper;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Validator\Compiler\ValidatorCompiler;
use Quiote\Validator\Compiler\ValidatorSource;

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
                $this->deriveInputSchema($route->module, $route->action, $httpMethod),
            );
        }

        return $definitions;
    }

    /**
     * Derive a JSON Schema for the tool's input from the action's declared
     * validators (docs/MCP_SERVER_PLAN.md §7): one declaration drives both
     * HTTP validation and the MCP schema. Resolves the action's
     * `{module}/Validate/{action}.xml` under `core.module_dir` (the same
     * convention {@see \Quiote\Validator\Compiler\Runtime\CompiledValidatorRegistry}
     * uses), parses it to the validator IR, and maps it for the action verb
     * this route's primary HTTP method dispatches to.
     *
     * Returns null -- caller falls back to a permissive schema -- when the
     * action has no XML validator file (e.g. it validates via a hand-written
     * fluent builder, which produces no IR), the file fails to parse, or the
     * rules yield nothing describable. Never throws: a schema-derivation
     * failure must not break tool discovery.
     *
     * @return array<string, mixed>|null
     */
    private function deriveInputSchema(string $module, string $action, string $httpMethod): ?array
    {
        $moduleDir = (string) Config::get('core.module_dir');
        if ($moduleDir === '') {
            return null;
        }

        $xmlPath = rtrim($moduleDir, '/') . '/' . $module . '/Validate/' . str_replace('.', '/', $action) . '.xml';
        if (!is_file($xmlPath)) {
            return null;
        }

        try {
            [$plan] = (new ValidatorCompiler())->parse(new ValidatorSource($xmlPath));

            return (new ValidatorSchemaMapper())->toInputSchema($plan, HttpMethodMapper::toActionMethod($httpMethod));
        } catch (\Throwable) {
            return null;
        }
    }
}
