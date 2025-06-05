<?php

namespace Agavi\Testing\Attributes;

use Attribute;

/**
 * Attribute to specify the isolation environment for Agavi tests
 * 
 * @package    agavi
 * @subpackage testing
 * @author     Agavi Team
 * @since      2.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AgaviIsolationEnvironment
{
    public function __construct(
        public readonly string $environment
    ) {
    }
}
