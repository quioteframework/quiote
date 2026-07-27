<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Constrains a numeric property's value. Backed by Quiote\Validator\NumberValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Range
{
    public function __construct(
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly ?string $message = null,
    ) {
    }
}
