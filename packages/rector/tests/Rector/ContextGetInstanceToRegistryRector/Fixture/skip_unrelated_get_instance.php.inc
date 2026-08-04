<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Rector\Tests\Rector\ContextGetInstanceToRegistryRector\Source\NotAContext;
use Quiote\Action\Action;

final class UsesAnotherSingleton extends Action
{
    public function executeRead(): mixed
    {
        // getInstance() is a common static name. Only a Quiote Context is rewritten.
        return NotAContext::getInstance();
    }
}
