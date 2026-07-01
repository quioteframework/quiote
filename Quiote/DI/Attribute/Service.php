<?php

namespace Quiote\DI\Attribute;

use Quiote\DI\Container;
use Attribute;

/**
 * Class-level discovery marker + scope declaration for services (docs/DI_MIGRATION_PLAN.md,
 * Phase 0/3). Lets the container (or a future scanner) discriminate services from arbitrary
 * autowireable classes without a forced base class or marker interface.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Service
{
    public function __construct(public string $scope = Container::SCOPE_SINGLETON) {}
}
