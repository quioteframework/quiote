<?php

namespace Agavi\Testing\Attributes;

use Attribute;

/**
 * Attribute to specify that cache should be cleared in isolation tests
 * 
 * @package    agavi
 * @subpackage testing
 * @author     Agavi Team
 * @since      2.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AgaviClearIsolationCache
{
    public function __construct() {
    }
}
