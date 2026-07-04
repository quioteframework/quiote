<?php

namespace Quiote\Mcp\Compiler;

use Quiote\Validator\AndoperatorValidator;
use Quiote\Validator\BooleanValidator;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\DateTimeValidator;
use Quiote\Validator\EmailValidator;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\IsNotEmptyValidator;
use Quiote\Validator\IssetValidator;
use Quiote\Validator\JsonValidator;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\OperatorValidator;
use Quiote\Validator\RegexValidator;
use Quiote\Validator\StringValidator;

/**
 * Maps a {@see ValidatorPlan} (the format-independent validator IR, see
 * docs/VALIDATOR_COMPILER_PLAN.md) to a JSON Schema `inputSchema` for an
 * MCP tool exposed from a `#[Route]` action (docs/MCP_SERVER_PLAN.md §7) --
 * one declaration drives both HTTP validation and the tool's advertised
 * schema.
 *
 * Deliberately best-effort and *descriptive*, not a faithful re-encoding of
 * the validation logic: the emitted schema always keeps `additionalProperties:
 * true` and the real enforcement still happens when the tool call is
 * dispatched through the pipeline (the same validators run). So where a rule
 * doesn't map cleanly to JSON Schema (a negative regex match, an operator
 * group spanning several fields, an unrecognized validator class), we degrade
 * to a looser description rather than dropping the field or misrepresenting
 * it -- matching the plan's §15 "permissive schema + server-side validation"
 * stance.
 *
 * Only leaf validators keyed by a single request parameter contribute a
 * property. Operator groups (and/or/not/xor) are flattened -- their child
 * validators' fields are unioned in -- rather than modeled as
 * allOf/anyOf/oneOf/not, since the goal is "which fields exist and roughly
 * what they accept," not a provable schema.
 */
final class ValidatorSchemaMapper
{
    /**
     * @param string $methodToken The action method token (read/write/update/…,
     *        via {@see \Quiote\Execution\HttpMethodMapper}) the tool is bound
     *        to; only validators scoped to that method (or method-agnostic) apply.
     * @return array<string, mixed>|null A JSON Schema object, or null when the
     *         plan yields nothing describable (caller falls back to a permissive
     *         schema).
     */
    public function toInputSchema(ValidatorPlan $plan, string $methodToken): ?array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = [];
        /** @var array<string, true> $required (set, for dedup) */
        $required = [];

        $this->collect($plan->nodes, $methodToken, $properties, $required);

        if ($properties === [] && $required === []) {
            return null;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($required),
            'additionalProperties' => true,
        ];
    }

    /**
     * @param ValidatorNode[]                       $nodes
     * @param array<string, array<string, mixed>>   $properties
     * @param array<string, true>                   $required
     */
    private function collect(array $nodes, string $methodToken, array &$properties, array &$required): void
    {
        foreach ($nodes as $node) {
            if (!$this->appliesToMethod($node, $methodToken)) {
                continue;
            }

            if (is_subclass_of($node->validatorClass, OperatorValidator::class)) {
                // Flatten operator groups: union the fields their children
                // constrain, but don't propagate required-ness out of the
                // group -- a NOT/OR group's children describe alternatives or
                // prohibitions, not fields that must all be present. Collect
                // child properties with a throwaway required set.
                $childRequired = [];
                $this->collect($node->children, $methodToken, $properties, $childRequired);
                continue;
            }

            $argument = $this->soleArgument($node);
            if ($argument === null) {
                continue;
            }

            $fragment = $this->mapLeaf($node);
            $properties[$argument] = array_key_exists($argument, $properties)
                ? array_merge($properties[$argument], $fragment)
                : $fragment;

            if (($node->parameters['required'] ?? true) !== false) {
                $required[$argument] = true;
            }
        }
    }

    private function appliesToMethod(ValidatorNode $node, string $methodToken): bool
    {
        return in_array('', $node->methods, true) || in_array($methodToken, $node->methods, true);
    }

    /** The single request parameter a leaf validator keys on, or null if it isn't a simple single-argument leaf. */
    private function soleArgument(ValidatorNode $node): ?string
    {
        if (!array_is_list($node->arguments) || count($node->arguments) !== 1) {
            return null;
        }
        $argument = $node->arguments[0];

        return (is_string($argument) && $argument !== '') ? $argument : null;
    }

    /** @return array<string, mixed> */
    private function mapLeaf(ValidatorNode $node): array
    {
        $params = $node->parameters;

        return match ($node->validatorClass) {
            StringValidator::class => $this->withLength(['type' => 'string'], $params),
            EmailValidator::class => ['type' => 'string', 'format' => 'email'],
            BooleanValidator::class => ['type' => 'boolean'],
            JsonValidator::class => ['type' => 'string', 'contentMediaType' => 'application/json'],
            DateTimeValidator::class => ['type' => 'string', 'format' => 'date-time'],
            NumberValidator::class => $this->number($params),
            InarrayValidator::class => $this->enum($params),
            RegexValidator::class => $this->regex($params),
            IsNotEmptyValidator::class => ['type' => 'string', 'minLength' => 1],
            // A validator we don't have a mapping for: emit an unconstrained
            // property so the field name and its required-ness still surface.
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withLength(array $schema, array $params): array
    {
        if (array_key_exists('min', $params) && is_numeric($params['min'])) {
            $schema['minLength'] = (int) $params['min'];
        }
        if (array_key_exists('max', $params) && is_numeric($params['max'])) {
            $schema['maxLength'] = (int) $params['max'];
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function number(array $params): array
    {
        $type = (string) ($params['type'] ?? '');
        $schema = ['type' => in_array($type, ['int', 'integer'], true) ? 'integer' : 'number'];

        if (array_key_exists('min', $params) && is_numeric($params['min'])) {
            $schema['minimum'] = $params['min'] + 0;
        }
        if (array_key_exists('max', $params) && is_numeric($params['max'])) {
            $schema['maximum'] = $params['max'] + 0;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function enum(array $params): array
    {
        if (!array_key_exists('values', $params)) {
            return ['type' => 'string'];
        }
        $values = $params['values'];
        if (is_string($values)) {
            $separator = (string) ($params['sep'] ?? ',');
            $values = $separator === '' ? [$values] : array_map('trim', explode($separator, $values));
        }
        if (!is_array($values) || $values === []) {
            return ['type' => 'string'];
        }

        return ['enum' => array_values($values)];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function regex(array $params): array
    {
        $schema = ['type' => 'string'];
        $match = $params['match'] ?? true;
        $pattern = $params['pattern'] ?? null;

        // JSON Schema `pattern` is a positive match with no flags; a negative
        // match (`match: false`) or a flagged PCRE can't be faithfully
        // represented, so those degrade to a bare string rather than lie.
        if (is_string($pattern) && $this->truthy($match)) {
            $body = $this->stripPcreDelimiters($pattern);
            if ($body !== null) {
                $schema['pattern'] = $body;
            }
        }

        return $schema;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /** Strip the PCRE delimiters (and any trailing flags) from a pattern, or null if flags are present (unrepresentable in JSON Schema) or the pattern is malformed. */
    private function stripPcreDelimiters(string $pattern): ?string
    {
        if (strlen($pattern) < 2) {
            return null;
        }

        $open = $pattern[0];
        $close = match ($open) {
            '(' => ')',
            '{' => '}',
            '[' => ']',
            '<' => '>',
            default => $open,
        };

        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return null;
        }

        $flags = substr($pattern, $end + 1);
        if ($flags !== '') {
            // Flagged patterns (i, u, s, …) have no JSON Schema equivalent.
            return null;
        }

        return substr($pattern, 1, $end - 1);
    }
}
