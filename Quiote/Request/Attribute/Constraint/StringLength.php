<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Constrains a string property's length. Backed by Quiote\Validator\StringValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class StringLength
{
    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly ?string $message = null,
    ) {
    }
}
