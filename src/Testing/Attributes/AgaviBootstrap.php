<?php

namespace Agavi\Testing\Attributes;

use Attribute;

/**
 * Attribute to specify whether Agavi should be bootstrapped in isolation tests
 * 
 * @package    agavi
 * @subpackage testing
 * @author     Agavi Team
 * @since      2.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AgaviBootstrap
{
    public function __construct(
        public readonly bool $bootstrap = true
    ) {
    }
}
