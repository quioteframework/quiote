<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Requires the property's value to be a parseable date/time. Backed by
 * Quiote\Validator\DateTimeValidator, which requires `core.use_translation`
 * to be enabled -- see that class's docblock.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class DateTimeType
{
    public function __construct(
        public readonly ?string $message = null,
    ) {
    }
}
