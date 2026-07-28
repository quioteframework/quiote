<?php

namespace Quiote\Mcp\Compiler;

use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\JsonSchema\ValidatorSchemaMapper as CoreValidatorSchemaMapper;

/**
 * @deprecated Since 1.2.5, use {@see CoreValidatorSchemaMapper}. The mapper
 *             moved into core once OpenAPI generation became a second consumer
 *             of it -- validator IR to JSON Schema was never MCP-specific.
 *             Kept as a forwarding shim so existing callers of the published
 *             package keep working.
 */
final class ValidatorSchemaMapper
{
    private readonly CoreValidatorSchemaMapper $mapper;

    public function __construct()
    {
        $this->mapper = new CoreValidatorSchemaMapper();
    }

    /** @return array<string, mixed>|null */
    public function toInputSchema(ValidatorPlan $plan, string $methodToken): ?array
    {
        return $this->mapper->toInputSchema($plan, $methodToken);
    }
}
