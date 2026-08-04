<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class CallsWithoutAModelName extends Action
{
    public function executeRead(Context $context): mixed
    {
        // getModel() needs a name; a no-argument call is not the accessor this rule knows.
        return $context->getModel();
    }
}
