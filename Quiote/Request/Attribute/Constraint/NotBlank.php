<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Requires the property's value to be present and non-empty. Backed by
 * Quiote\Validator\IsNotEmptyValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class NotBlank
{
    public function __construct(
        public readonly ?string $message = null,
    ) {
    }
}
