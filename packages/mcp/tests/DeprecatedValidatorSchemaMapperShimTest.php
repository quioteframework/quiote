<?php

use Quiote\Mcp\Compiler\ValidatorSchemaMapper;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\StringValidator;

/**
 * The mapper moved to Quiote\Validator\Compiler\JsonSchema once OpenAPI
 * generation became a second consumer of it; the package's old class name stays
 * behind as a forwarding shim, so anyone who called it directly keeps working.
 * Behaviour itself is covered by the core ValidatorSchemaMapperTest.
 */
final class DeprecatedValidatorSchemaMapperShimTest extends PhpUnitTestCase
{
    public function testForwardsToTheCoreMapper(): void
    {
        $plan = new ValidatorPlan([
            new ValidatorNode('title', StringValidator::class, ['title'], '', ['min' => 3], [], [''], [], []),
        ], 'test');

        $this->assertSame([
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string', 'minLength' => 3]],
            'required' => ['title'],
            'additionalProperties' => true,
        ], (new ValidatorSchemaMapper())->toInputSchema($plan, 'write'));
    }
}
