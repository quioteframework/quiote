<?php
namespace Quiote\Request\Attribute;

use Attribute;

/**
 * Marks a class as a request-mappable DTO. An Action method parameter typed
 * with a #[MapRequest] class has its validators derived from the class's
 * constructor-parameter constraint attributes (see Quiote\Request\Attribute\Constraint)
 * and, once validation passes, is constructed and injected by ActionResolver.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MapRequest
{
}
