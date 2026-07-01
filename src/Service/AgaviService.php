<?php

namespace Agavi\Service;

use Agavi\AgaviContext;

/**
 * Optional, transitional base for services (docs/DI_MIGRATION_PLAN.md, Phase 3).
 *
 * This is scaffolding, not a permanent parent: it exists so a half-migrated service
 * can still reach `$this->getContext()->getModel('Other')` while its collaborators are
 * converted to constructor injection. It does not extend AgaviModel — the DTO-style
 * getModel() convention and the service convention are deliberately un-conflated (see
 * docs/DI_MIGRATION_PLAN.md §2.5).
 *
 * The end state for a service is a POPO with constructor-injected dependencies and no
 * base class at all. Extending this out of habit just recreates the service-locator
 * pattern under a new name — reach for constructor injection first.
 */
abstract class AgaviService implements AgaviServiceInterface
{
    public function __construct(protected readonly AgaviContext $context) {}

    public function getContext(): AgaviContext
    {
        return $this->context;
    }
}
