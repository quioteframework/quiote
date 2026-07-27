<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Constrains a string property's value against a regular expression. Backed
 * by Quiote\Validator\RegexValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Regexp
{
    public function __construct(
        public readonly string $pattern,
        public readonly bool $match = true,
        public readonly ?string $message = null,
    ) {
    }
}
