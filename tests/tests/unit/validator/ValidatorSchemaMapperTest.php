<?php

use PHPUnit\Framework\TestCase;
use Quiote\Validator\AndoperatorValidator;
use Quiote\Validator\BooleanValidator;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\JsonSchema\ValidatorSchemaMapper;
use Quiote\Validator\EmailValidator;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\IsNotEmptyValidator;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\RegexValidator;
use Quiote\Validator\StringValidator;

/**
 * ValidatorSchemaMapper maps the validator IR (ValidatorPlan/ValidatorNode) to
 * a JSON Schema describing an action's request parameters -- what an
 * action-as-tool advertises as its `inputSchema` and what an OpenAPI operation
 * describes as its parameters/requestBody. Exercised against hand-built
 * IR nodes -- no XML parse, no bootstrap -- so
 * this covers the mapping logic in isolation from discovery/dispatch.
 */
final class ValidatorSchemaMapperTest extends TestCase
{
    /**
     * @param array<string, mixed> $parameters
     * @param string[]             $methods
     * @param list<ValidatorNode>  $children
     */
    private function node(
        string $class,
        string $argument,
        array $parameters = [],
        array $methods = [''],
        array $children = [],
    ): ValidatorNode {
        return new ValidatorNode(
            'v_' . $argument,
            $class,
            [$argument],
            '',
            $parameters,
            [],
            $methods,
            [$argument],
            $children,
        );
    }

    /**
     * @param list<ValidatorNode> $nodes
     * @return array<string, mixed>|null
     */
    private function mapOrNull(array $nodes, string $methodToken = 'read'): ?array
    {
        return (new ValidatorSchemaMapper())->toInputSchema(new ValidatorPlan($nodes, 'test'), $methodToken);
    }

    /**
     * @param list<ValidatorNode> $nodes
     * @return array<mixed, mixed> The mapped schema, asserted to exist.
     */
    private function map(array $nodes, string $methodToken = 'read'): array
    {
        $schema = $this->mapOrNull($nodes, $methodToken);
        $this->assertIsArray($schema);

        return $schema;
    }

    /**
     * @param array<mixed, mixed> $schema
     * @return array<string, mixed> The schema's `properties` map, asserted to exist.
     */
    private function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        $this->assertIsArray($properties);

        $keyed = [];
        foreach ($properties as $name => $property) {
            $this->assertIsString($name);
            $keyed[$name] = $property;
        }

        return $keyed;
    }

    /**
     * @param array<mixed, mixed> $schema
     * @return array<mixed, mixed> One property's schema fragment, asserted to exist.
     */
    private function property(array $schema, string $name): array
    {
        $properties = $this->properties($schema);
        $this->assertArrayHasKey($name, $properties);
        $property = $properties[$name];
        $this->assertIsArray($property);

        return $property;
    }

    /**
     * @param array<mixed, mixed> $schema
     * @return list<string> The schema's `required` list, asserted to exist.
     */
    private function required(array $schema): array
    {
        $required = $schema['required'] ?? null;
        $this->assertIsArray($required);

        $names = [];
        foreach ($required as $name) {
            $this->assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    public function testStringWithLengthBoundsAndRequired(): void
    {
        $schema = $this->map([$this->node(StringValidator::class, 'name', ['min' => '2', 'max' => '32'])]);

        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertSame(['type' => 'string', 'minLength' => 2, 'maxLength' => 32], $this->property($schema, 'name'));
        $this->assertSame(['name'], $this->required($schema));
        $this->assertTrue($schema['additionalProperties'] ?? null);
    }

    public function testRequiredFalseKeepsPropertyButOmitsFromRequired(): void
    {
        $schema = $this->map([$this->node(StringValidator::class, 'nickname', ['required' => false])]);

        $this->assertArrayHasKey('nickname', $this->properties($schema));
        $this->assertSame([], $this->required($schema));
    }

    public function testIntegerNumberWithBounds(): void
    {
        $schema = $this->map([$this->node(NumberValidator::class, 'age', ['type' => 'integer', 'min' => '0', 'max' => '150'])]);

        $this->assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 150], $this->property($schema, 'age'));
    }

    public function testFloatNumberIsTypeNumber(): void
    {
        $schema = $this->map([$this->node(NumberValidator::class, 'ratio', ['min' => '0.5'])]);

        $property = $this->property($schema, 'ratio');
        $this->assertSame('number', $property['type'] ?? null);
        $this->assertSame(0.5, $property['minimum'] ?? null);
    }

    public function testEmailFormat(): void
    {
        $schema = $this->map([$this->node(EmailValidator::class, 'contact')]);

        $this->assertSame(['type' => 'string', 'format' => 'email'], $this->property($schema, 'contact'));
    }

    public function testBooleanType(): void
    {
        $schema = $this->map([$this->node(BooleanValidator::class, 'flag')]);

        $this->assertSame(['type' => 'boolean'], $this->property($schema, 'flag'));
    }

    public function testInarrayBecomesEnumSplitOnSeparator(): void
    {
        $schema = $this->map([$this->node(InarrayValidator::class, 'status', ['values' => 'pending, approved, rejected'])]);

        $this->assertSame(['enum' => ['pending', 'approved', 'rejected']], $this->property($schema, 'status'));
    }

    public function testInarrayAcceptsAnArrayOfValues(): void
    {
        $schema = $this->map([$this->node(InarrayValidator::class, 'status', ['values' => ['a', 'b']])]);

        $this->assertSame(['enum' => ['a', 'b']], $this->property($schema, 'status'));
    }

    public function testRegexPositiveMatchStripsDelimiters(): void
    {
        $schema = $this->map([$this->node(RegexValidator::class, 'code', ['pattern' => '/^[A-Z]{3}$/', 'match' => true])]);

        $this->assertSame(['type' => 'string', 'pattern' => '^[A-Z]{3}$'], $this->property($schema, 'code'));
    }

    public function testRegexWithFlagsDegradesToPlainString(): void
    {
        $schema = $this->map([$this->node(RegexValidator::class, 'code', ['pattern' => '/^abc$/i', 'match' => true])]);

        $this->assertSame(['type' => 'string'], $this->property($schema, 'code'));
    }

    public function testNegativeRegexMatchDegradesToPlainString(): void
    {
        $schema = $this->map([$this->node(RegexValidator::class, 'code', ['pattern' => '/^bad$/', 'match' => false])]);

        $this->assertSame(['type' => 'string'], $this->property($schema, 'code'));
    }

    public function testIsNotEmptyImpliesNonEmptyStringAndRequired(): void
    {
        $schema = $this->map([$this->node(IsNotEmptyValidator::class, 'title')]);

        $this->assertSame(['type' => 'string', 'minLength' => 1], $this->property($schema, 'title'));
        $this->assertSame(['title'], $this->required($schema));
    }

    public function testUnknownValidatorEmitsUnconstrainedPropertyStillRequired(): void
    {
        $schema = $this->map([$this->node('App\\Custom\\WhateverValidator', 'thing')]);

        $this->assertSame([], $this->property($schema, 'thing'));
        $this->assertSame(['thing'], $this->required($schema));
    }

    public function testMethodScopingExcludesOtherVerbs(): void
    {
        $nodes = [
            $this->node(StringValidator::class, 'always'),
            $this->node(StringValidator::class, 'writeOnly', [], ['write']),
        ];

        $readProperties = $this->properties($this->map($nodes, 'read'));
        $this->assertArrayHasKey('always', $readProperties);
        $this->assertArrayNotHasKey('writeOnly', $readProperties);

        $writeProperties = $this->properties($this->map($nodes, 'write'));
        $this->assertArrayHasKey('always', $writeProperties);
        $this->assertArrayHasKey('writeOnly', $writeProperties);
    }

    public function testOperatorGroupFlattensChildFieldsWithoutPropagatingRequired(): void
    {
        $group = $this->node(AndoperatorValidator::class, 'ignored', [], [''], [
            $this->node(StringValidator::class, 'a', ['min' => '1']),
            $this->node(EmailValidator::class, 'b'),
        ]);

        $schema = $this->map([$group]);

        $properties = $this->properties($schema);
        $this->assertArrayHasKey('a', $properties);
        $this->assertArrayHasKey('b', $properties);
        // Group membership doesn't force required-ness out of the group.
        $this->assertSame([], $this->required($schema));
    }

    public function testMergesMultipleValidatorsOnTheSameArgument(): void
    {
        $schema = $this->map([
            $this->node(IsNotEmptyValidator::class, 'name'),
            $this->node(StringValidator::class, 'name', ['max' => '10']),
        ]);

        $property = $this->property($schema, 'name');
        $this->assertSame('string', $property['type'] ?? null);
        $this->assertSame(10, $property['maxLength'] ?? null);
        $this->assertSame(['name'], $this->required($schema));
    }

    public function testEmptyPlanYieldsNull(): void
    {
        $this->assertNull($this->mapOrNull([]));
    }
}
