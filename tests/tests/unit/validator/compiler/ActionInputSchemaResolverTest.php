<?php

use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Validator\Compiler\JsonSchema\ActionInputSchemaResolver;

/**
 * Deriving an action's request schema from whichever way it declares its
 * validators. Both conventions are represented by sandbox fixtures:
 * McpActionTool.Greet has Validate/Greet.xml, McpActionTool.FluentValidator has
 * registerWriteValidators() and no XML file at all, and McpActionTool.MapRequest
 * declares its constraints on a #[MapRequest] DTO (which registers the same
 * validators under the hood).
 */
final class ActionInputSchemaResolverTest extends PhpUnitTestCase
{
    private function controller(): Controller
    {
        return Context::getInstance('mcp-action-tool-test')->getController();
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string> The derived schema's property names, in order.
     */
    private function propertyNames(array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        $this->assertIsArray($properties);

        $names = [];
        foreach (array_keys($properties) as $name) {
            $this->assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    public function testDerivesFromTheValidatorXmlFileConvention(): void
    {
        $schema = (new ActionInputSchemaResolver())->resolve($this->controller(), 'McpActionTool', 'Greet', 'read');

        $this->assertSame([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 50]],
            'required' => ['name'],
            'additionalProperties' => true,
        ], $schema);
    }

    public function testDerivesFromTheFluentValidatorBuilderHookWhenThereIsNoXml(): void
    {
        $schema = (new ActionInputSchemaResolver())->resolve($this->controller(), 'McpActionTool', 'FluentValidator', 'write');

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20],
                'author_email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['title'],
            'additionalProperties' => true,
        ], $schema);
    }

    public function testDerivesFromMapRequestDtoConstraints(): void
    {
        $schema = (new ActionInputSchemaResolver())->resolve($this->controller(), 'McpActionTool', 'MapRequestTool', 'write');

        $this->assertNotNull($schema);
        $this->assertSame(['title', 'authorEmail'], $this->propertyNames($schema));
    }

    public function testMethodTokenWithoutValidatorsYieldsNull(): void
    {
        // FluentValidatorAction only registers validators for the write token.
        $this->assertNull((new ActionInputSchemaResolver())->resolve($this->controller(), 'McpActionTool', 'FluentValidator', 'update'));
    }

    public function testAnActionWithoutAnyValidatorsYieldsNull(): void
    {
        $this->assertNull((new ActionInputSchemaResolver())->resolve($this->controller(), 'McpActionTool', 'MultiVerb', 'write'));
    }

    public function testAnUnresolvableActionYieldsNullInsteadOfThrowing(): void
    {
        $this->assertNull((new ActionInputSchemaResolver())->resolve($this->controller(), 'NoSuchModule', 'NoSuchAction', 'read'));
    }

    public function testRepeatedResolutionOfTheSameTripleIsStable(): void
    {
        // Memoized, and -- more importantly -- registering the same validators
        // again must not duplicate or double-require anything.
        $resolver = new ActionInputSchemaResolver();
        $first = $resolver->resolve($this->controller(), 'McpActionTool', 'FluentValidator', 'write');
        $second = $resolver->resolve($this->controller(), 'McpActionTool', 'FluentValidator', 'write');

        $this->assertSame($first, $second);
    }

    public function testResolveForActionUsesAnInstanceTheCallerAlreadyHas(): void
    {
        $controller = $this->controller();
        $action = $controller->createActionInstance('McpActionTool', 'Greet');

        $schema = (new ActionInputSchemaResolver())->resolveForAction($controller, $action, 'McpActionTool', 'Greet', 'read');

        $this->assertNotNull($schema);
        $this->assertSame(['name'], $this->propertyNames($schema));
    }
}
