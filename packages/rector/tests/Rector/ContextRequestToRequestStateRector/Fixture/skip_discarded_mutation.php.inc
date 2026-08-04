<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class DiscardsTheMutation extends Action
{
    public function executeRead(Context $context): void
    {
        // Already a no-op: setAttribute() returns a new instance. Rewriting it would make a dead
        // mutation look deliberate.
        $context->getRequest()->setAttribute('populate', ['form' => 'x']);
    }
}
