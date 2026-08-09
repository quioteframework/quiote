<?php

namespace Quiote\Service;

use Quiote\Context;

/**
 * Optional, transitional base for services. This is scaffolding, not a permanent parent: it exists so
 * a service still being converted to constructor injection has somewhere to reach the container from,
 * through `$this->getContext()->getContainer()`. It does not extend Model — the DTO-style getModel()
 * convention and the service convention are deliberately un-conflated.
 *
 * The end state for a service is a POPO with constructor-injected dependencies and no base class at
 * all. Extending this out of habit just recreates the service-locator pattern under a new name —
 * reach for constructor injection first.
 */
abstract class Service implements ServiceInterface
{
    public function __construct(protected readonly Context $context) {}

    /** Returns the context this service was constructed with. */
    public function getContext(): Context
    {
        return $this->context;
    }
}
