<?php

namespace Quiote\Service;

use Quiote\Context;

/**
 * Optional, transitional base for services (docs/DI_MIGRATION_PLAN.md, Phase 3).
 * This is scaffolding, not a permanent parent: it exists so a half-migrated service
 * can still reach `$this->getContext()->getModel('Other')` while its collaborators are
 * converted to constructor injection. It does not extend Model — the DTO-style
 * getModel() convention and the service convention are deliberately un-conflated (see
 * docs/DI_MIGRATION_PLAN.md §2.5).
 * The end state for a service is a POPO with constructor-injected dependencies and no
 * base class at all. Extending this out of habit just recreates the service-locator
 * pattern under a new name — reach for constructor injection first.
 */
abstract class Service implements ServiceInterface
{
    public function __construct(protected readonly Context $context) {}

    public function getContext(): Context
    {
        return $this->context;
    }
}
