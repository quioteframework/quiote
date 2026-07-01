<?php

namespace Quiote\DI\Attribute;

use Attribute;

/**
 * Parameter-level override: resolve this parameter from the container by an explicit id
 * instead of autowiring by type. Use to pick among multiple implementations of an
 * interface, or to name a service registered under a role/alias rather than a class.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(public string $id) {}
}
