<?php

namespace Agavi\DI\Attribute;

use Attribute;

/**
 * Parameter-level override: inject this literal scalar/config value instead of autowiring
 * by type. Use for the `<ae:parameter>`-style values (cookie names, table names, modes)
 * that have no type to autowire against.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Autowire
{
    public function __construct(public mixed $value) {}
}
