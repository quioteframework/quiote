<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Requires the property's value to be a valid email address. Backed by
 * Quiote\Validator\EmailValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Email
{
    public function __construct(
        public readonly ?string $message = null,
    ) {
    }
}
