<?php

namespace Quiote\Mcp\Compiler;

use Quiote\Action\Action;
use Quiote\Config\Config;
use Quiote\Controller\Controller;
use Quiote\Execution\HttpMethodMapper;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\ValidatorCompiler;
use Quiote\Validator\Compiler\ValidatorSource;
use Quiote\Validator\IValidatorContainer;
use Quiote\Validator\Validator;

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
                $this->deriveInputSchema($controller, $action, $route->module, $route->action, $httpMethod),
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

    /**
     * Derive a JSON Schema for the tool's input from the action's declared
     * validators: one declaration drives both HTTP validation and the MCP
     * schema. Tries the `{module}/Validate/{action}.xml` file convention
     * first (the same one {@see \Quiote\Validator\Compiler\Runtime\CompiledValidatorRegistry}
     * uses); if none exists, falls back to registering the action's fluent
     * `register{Method}Validators()`/`registerValidators()` hook (the
     * convention every documented example and this app's own actions
     * actually use -- see {@see \Quiote\Validator\Compiler\Runtime\ValidatorBuilder})
     * against a throwaway ValidationManager and reading back whatever
     * validators it added, so the two "validator file" concepts (XML vs.
     * fluent PHP) both feed the same schema mapper instead of only the XML
     * one being understood here.
     *
     * Returns null -- caller falls back to a permissive schema -- when
     * neither source yields anything describable. Never throws: a
     * schema-derivation failure must not break tool discovery.
     *
     * @return array<string, mixed>|null
     */
    private function deriveInputSchema(Controller $controller, Action $action, string $module, string $actionName, string $httpMethod): ?array
    {
        $methodToken = HttpMethodMapper::toActionMethod($httpMethod);

        $fromXml = $this->deriveInputSchemaFromXml($module, $actionName, $methodToken);
        if ($fromXml !== null) {
            return $fromXml;
        }

        return $this->deriveInputSchemaFromFluentBuilder($controller, $action, $module, $actionName, $methodToken);
    }

    /** @return array<string, mixed>|null */
    private function deriveInputSchemaFromXml(string $module, string $action, string $methodToken): ?array
    {
        $moduleDir = Config::getString('core.module_dir', '');
        if ($moduleDir === '') {
            return null;
        }

        $xmlPath = rtrim($moduleDir, '/') . '/' . $module . '/Validate/' . str_replace('.', '/', $action) . '.xml';
        if (!is_file($xmlPath)) {
            return null;
        }

        try {
            [$plan] = (new ValidatorCompiler())->parse(new ValidatorSource($xmlPath));

            return (new ValidatorSchemaMapper())->toInputSchema($plan, $methodToken);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Registers the action's validators the same way a real request would
     * (via its `register{Method}Validators()`/`registerValidators()` hook),
     * against a throwaway ValidationManager that's discarded afterwards --
     * this only builds the validator tree, it never calls execute() on it,
     * so no request-validation side effect (exports, incidents) occurs.
     * @return array<string, mixed>|null
     */
    private function deriveInputSchemaFromFluentBuilder(Controller $controller, Action $action, string $module, string $actionName, string $methodToken): ?array
    {
        try {
            $initContext = new LightweightActionInitContext(
                $controller->getContext(),
                $module,
                $actionName,
                $methodToken,
                'html',
                null,
                $controller->getGlobalResponse(),
            );
            $action->initialize($initContext);

            $registerMethod = 'register' . ucfirst($methodToken) . 'Validators';
            if (!is_callable([$action, $registerMethod])) {
                $registerMethod = 'registerValidators';
            }
            if (!is_callable([$action, $registerMethod])) {
                return null;
            }
            $action->$registerMethod();

            $manager = $initContext->getValidationManager();
            if (!$manager instanceof IValidatorContainer) {
                return null;
            }

            $nodes = $this->toValidatorNodes($manager->getChilds());
            if ($nodes === []) {
                return null;
            }

            $plan = new ValidatorPlan($nodes, $module . '/' . $actionName . ' (fluent ValidatorBuilder)');

            return (new ValidatorSchemaMapper())->toInputSchema($plan, $methodToken);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Converts a live validator tree (already registered against a
     * ValidationManager/operator group, never executed) into the
     * format-independent IR {@see ValidatorSchemaMapper} consumes -- the
     * same IR the XML front-end produces, so both sources share one mapper.
     * Every node is method-agnostic ('') here: register{Method}Validators()
     * is already called once per method token, so whatever it registered
     * during THIS call inherently only applies to that one method.
     * @param array<string, Validator> $validators
     * @return list<ValidatorNode>
     */
    private function toValidatorNodes(array $validators): array
    {
        $nodes = [];
        foreach ($validators as $validator) {
            $children = $validator instanceof IValidatorContainer
                ? $this->toValidatorNodes($validator->getChilds())
                : [];

            $nodes[] = new ValidatorNode(
                name: $validator->getName() ?? '',
                validatorClass: $validator::class,
                arguments: array_values($validator->getArguments()),
                base: '',
                parameters: $validator->getParameters(),
                errors: [],
                methods: [''],
                declaredNames: [],
                children: $children,
            );
        }

        return $nodes;
    }
}
