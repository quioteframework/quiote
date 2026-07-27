<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Requires the property's value to be a boolean. Backed by
 * Quiote\Validator\BooleanValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class BooleanType
{
    public function __construct(
        public readonly ?string $message = null,
    ) {
    }
}
