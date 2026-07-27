<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Requires the property's value to be one of a fixed allowlist. Backed by
 * Quiote\Validator\InarrayValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Choice
{
    /**
     * @param array<int, string|int|float> $values
     */
    public function __construct(
        public readonly array $values,
        public readonly ?string $message = null,
    ) {
    }
}
