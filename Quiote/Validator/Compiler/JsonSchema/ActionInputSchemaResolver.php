<?php
declare(strict_types=1);

namespace Quiote\Validator\Compiler\JsonSchema;

use Quiote\Action\Action;
use Quiote\Config\Config;
use Quiote\Controller\Controller;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\ValidatorCompiler;
use Quiote\Validator\Compiler\ValidatorSource;
use Quiote\Validator\IValidatorContainer;
use Quiote\Validator\Validator;

/**
 * Derives a JSON Schema for one action+method's request parameters from
 * whatever validators that action declares, so a single declaration can drive
 * HTTP validation, an MCP tool's `inputSchema` and an OpenAPI operation's
 * parameters/requestBody.
 *
 * Both "validator file" conventions feed the same {@see ValidatorSchemaMapper}:
 * the `{module}/Validate/{action}.xml` file convention
 * ({@see \Quiote\Validator\Compiler\Runtime\CompiledValidatorRegistry} uses the
 * same path) is tried first, and failing that the action's fluent
 * `register{Method}Validators()`/`registerValidators()` hook (the convention
 * every documented example uses -- see
 * {@see \Quiote\Validator\Compiler\Runtime\ValidatorBuilder}) is registered
 * against a throwaway ValidationManager and read back. That throwaway manager
 * is never executed, so no request-validation side effect (exports, incidents)
 * occurs.
 *
 * Returns null -- callers fall back to a permissive schema, or to describing
 * nothing -- when neither source yields anything describable. Never throws: a
 * schema-derivation failure must not break tool discovery or doc generation.
 * @since      1.2.5
 */
final class ActionInputSchemaResolver
{
    /** @var array<string, array<string, mixed>|null> Memoized per module/action/method: doc generation asks for the same triple once per HTTP verb that maps to the same token. */
    private array $cache = [];

    private readonly ValidatorSchemaMapper $mapper;

    public function __construct(?ValidatorSchemaMapper $mapper = null)
    {
        $this->mapper = $mapper ?? new ValidatorSchemaMapper();
    }

    /**
     * Resolve for a module/action pair, instantiating the action the same way
     * {@see Controller::createActionInstance()} does for a real request.
     * @param string $methodToken read/write/update/remove/... (see {@see \Quiote\Execution\HttpMethodMapper}).
     * @return array<string, mixed>|null
     */
    public function resolve(Controller $controller, string $module, string $action, string $methodToken): ?array
    {
        $key = $module . '/' . $action . '/' . $methodToken;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $fromXml = $this->fromXml($module, $action, $methodToken);
        if ($fromXml !== null) {
            return $this->cache[$key] = $fromXml;
        }

        try {
            $instance = $controller->createActionInstance($module, $action);
        } catch (\Throwable) {
            return $this->cache[$key] = null;
        }

        return $this->cache[$key] = $this->fromFluentBuilder($controller, $instance, $module, $action, $methodToken);
    }

    /**
     * Resolve for an action instance the caller already has. The instance is
     * initialized and its validator hook called, so pass a freshly created one
     * (registering the same validators twice would duplicate them).
     * @return array<string, mixed>|null
     */
    public function resolveForAction(Controller $controller, Action $action, string $module, string $actionName, string $methodToken): ?array
    {
        return $this->fromXml($module, $actionName, $methodToken)
            ?? $this->fromFluentBuilder($controller, $action, $module, $actionName, $methodToken);
    }

    /** @return array<string, mixed>|null */
    private function fromXml(string $module, string $action, string $methodToken): ?array
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

            return $this->mapper->toInputSchema($plan, $methodToken);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function fromFluentBuilder(Controller $controller, Action $action, string $module, string $actionName, string $methodToken): ?array
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

            return $this->mapper->toInputSchema($plan, $methodToken);
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
                parameters: $this->stringKeyed($validator->getParameters()),
                errors: [],
                methods: [''],
                declaredNames: [],
                children: $children,
            );
        }

        return $nodes;
    }

    /**
     * A ParameterHolder's keys are `int|string`; the IR's are `string`. A
     * numeric parameter name means nothing to the schema mapper (which looks
     * parameters up by name), so those are stringified rather than dropped.
     * @param array<int|string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function stringKeyed(array $parameters): array
    {
        $keyed = [];
        foreach ($parameters as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
