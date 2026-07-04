<?php

use PHPUnit\Framework\TestCase;
use Quiote\Mcp\Compiler\ValidatorSchemaMapper;
use Quiote\Validator\AndoperatorValidator;
use Quiote\Validator\BooleanValidator;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\EmailValidator;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\IsNotEmptyValidator;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\RegexValidator;
use Quiote\Validator\StringValidator;

/**
 * ValidatorSchemaMapper maps the validator IR (ValidatorPlan/ValidatorNode) to
 * a JSON Schema for an action-as-tool's input (docs/MCP_SERVER_PLAN.md §7).
 * Exercised against hand-built IR nodes -- no XML parse, no bootstrap -- so
 * this covers the mapping logic in isolation from discovery/dispatch.
 */
final class ValidatorSchemaMapperTest extends TestCase
{
    /**
     * @param array<string, mixed> $parameters
     * @param string[]             $methods
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

    private function map(array $nodes, string $methodToken = 'read'): ?array
    {
        return (new ValidatorSchemaMapper())->toInputSchema(new ValidatorPlan($nodes, 'test'), $methodToken);
    }

    public function testStringWithLengthBoundsAndRequired(): void
    {
        $schema = $this->map([$this->node(StringValidator::class, 'name', ['min' => '2', 'max' => '32'])]);

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['type' => 'string', 'minLength' => 2, 'maxLength' => 32], $schema['properties']['name']);
        $this->assertSame(['name'], $schema['required']);
        $this->assertTrue($schema['additionalProperties']);
    }

    public function testRequiredFalseKeepsPropertyButOmitsFromRequired(): void
    {
        $schema = $this->map([$this->node(StringValidator::class, 'nickname', ['required' => false])]);

        $this->assertArrayHasKey('nickname', $schema['properties']);
        $this->assertSame([], $schema['required']);
    }

    public function testIntegerNumberWithBounds(): void
    {
        $schema = $this->map([$this->node(NumberValidator::class, 'age', ['type' => 'integer', 'min' => '0', 'max' => '150'])]);

        $this->assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 150], $schema['properties']['age']);
    }

    public function testFloatNumberIsTypeNumber(): void
    {
        $schema = $this->map([$this->node(NumberValidator::class, 'ratio', ['min' => '0.5'])]);

        $this->assertSame('number', $schema['properties']['ratio']['type']);
        $this->assertSame(0.5, $schema['properties']['ratio']['minimum']);
    }

    public function testEmailFormat(): void
    {
        $schema = $this->map([$this->node(EmailValidator::class, 'contact')]);

        $this->assertSame(['type' => 'string', 'format' => 'email'], $schema['properties']['contact']);
    }

    public function testBooleanType(): void
    {
        $schema = $this->map([$this->node(BooleanValidator::class, 'flag')]);

        $this->assertSame(['type' => 'boolean'], $schema['properties']['flag']);
    }

    public function testInarrayBecomesEnumSplitOnSeparator(): void
    {
        $schema = $this->map([$this->node(InarrayValidator::class, 'status', ['values' => 'pending, approved, rejected'])]);

        $this->assertSame(['enum' => ['pending', 'approved', 'rejected']], $schema['properties']['status']);
    }

    public function testInarrayAcceptsAnArrayOfValues(): void
    {
        $schema = $this->map([$this->node(InarrayValidator::class, 'status', ['values' => ['a', 'b']])]);

        $this->assertSame(['enum' => ['a', 'b']], $schema['properties']['status']);
    }

    public function testRegexPositiveMatchStripsDelimiters(): void
    {
        $schema = $this->map([$this->node(RegexValidator::class, 'code', ['pattern' => '/^[A-Z]{3}$/', 'match' => true])]);

        $this->assertSame(['type' => 'string', 'pattern' => '^[A-Z]{3}$'], $schema['properties']['code']);
    }

    public function testRegexWithFlagsDegradesToPlainString(): void
    {
        $schema = $this->map([$this->node(RegexValidator::class, 'code', ['pattern' => '/^abc$/i', 'match' => true])]);

        $this->assertSame(['type' => 'string'], $schema['properties']['code']);
    }

    public function testNegativeRegexMatchDegradesToPlainString(): void
    {
        $schema = $this->map([$this->node(RegexValidator::class, 'code', ['pattern' => '/^bad$/', 'match' => false])]);

        $this->assertSame(['type' => 'string'], $schema['properties']['code']);
    }

    public function testIsNotEmptyImpliesNonEmptyStringAndRequired(): void
    {
        $schema = $this->map([$this->node(IsNotEmptyValidator::class, 'title')]);

        $this->assertSame(['type' => 'string', 'minLength' => 1], $schema['properties']['title']);
        $this->assertSame(['title'], $schema['required']);
    }

    public function testUnknownValidatorEmitsUnconstrainedPropertyStillRequired(): void
    {
        $schema = $this->map([$this->node('App\\Custom\\WhateverValidator', 'thing')]);

        $this->assertSame([], $schema['properties']['thing']);
        $this->assertSame(['thing'], $schema['required']);
    }

    public function testMethodScopingExcludesOtherVerbs(): void
    {
        $nodes = [
            $this->node(StringValidator::class, 'always'),
            $this->node(StringValidator::class, 'writeOnly', [], ['write']),
        ];

        $readSchema = $this->map($nodes, 'read');
        $this->assertArrayHasKey('always', $readSchema['properties']);
        $this->assertArrayNotHasKey('writeOnly', $readSchema['properties']);

        $writeSchema = $this->map($nodes, 'write');
        $this->assertArrayHasKey('always', $writeSchema['properties']);
        $this->assertArrayHasKey('writeOnly', $writeSchema['properties']);
    }

    public function testOperatorGroupFlattensChildFieldsWithoutPropagatingRequired(): void
    {
        $group = $this->node(AndoperatorValidator::class, 'ignored', [], [''], [
            $this->node(StringValidator::class, 'a', ['min' => '1']),
            $this->node(EmailValidator::class, 'b'),
        ]);

        $schema = $this->map([$group]);

        $this->assertArrayHasKey('a', $schema['properties']);
        $this->assertArrayHasKey('b', $schema['properties']);
        // Group membership doesn't force required-ness out of the group.
        $this->assertSame([], $schema['required']);
    }

    public function testMergesMultipleValidatorsOnTheSameArgument(): void
    {
        $schema = $this->map([
            $this->node(IsNotEmptyValidator::class, 'name'),
            $this->node(StringValidator::class, 'name', ['max' => '10']),
        ]);

        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame(10, $schema['properties']['name']['maxLength']);
        $this->assertSame(['name'], $schema['required']);
    }

    public function testEmptyPlanYieldsNull(): void
    {
        $this->assertNull($this->map([]));
    }
}
