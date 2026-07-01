<?php

namespace Quiote\Testing\Attributes;

use Attribute;

/**
 * Attribute to specify the default context for Quiote isolation tests
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class IsolationDefaultContext
{
    public function __construct(
        public readonly string $context
    ) {
    }
}
