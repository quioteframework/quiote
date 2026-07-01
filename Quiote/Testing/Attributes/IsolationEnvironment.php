<?php

namespace Quiote\Testing\Attributes;

use Attribute;

/**
 * Attribute to specify the isolation environment for Quiote tests
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class IsolationEnvironment
{
    public function __construct(
        public readonly string $environment
    ) {
    }
}
