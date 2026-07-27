<?php
namespace Quiote\Request\Attribute\Constraint;

use Attribute;

/**
 * Requires the property's value to be a syntactically valid JSON string.
 * Backed by Quiote\Validator\JsonValidator.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class JsonType
{
    public function __construct(
        public readonly ?string $message = null,
    ) {
    }
}
