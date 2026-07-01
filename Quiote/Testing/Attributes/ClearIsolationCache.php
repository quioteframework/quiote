<?php

namespace Quiote\Testing\Attributes;

use Attribute;

/**
 * Attribute to specify that cache should be cleared in isolation tests
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ClearIsolationCache
{
    public function __construct() {
    }
}
