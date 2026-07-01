<?php

namespace Quiote\Testing\Attributes;

use Attribute;

/**
 * Attribute to specify whether Quiote should be bootstrapped in isolation tests
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Bootstrap
{
    public function __construct(
        public readonly bool $bootstrap = true
    ) {
    }
}
