<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;
use Quiote\Request\WebRequest;

final class DiscardsTheMutation
{
    public function executeRead(Context $context, WebRequest $rd): void
    {
        // setAttribute() returns a new instance; this statement already does nothing. Rewriting it
        // to $rd->setAttribute(...) would be identically broken but look deliberate.
        $context->getRequest()->setAttribute('populate', ['form' => 'x']);
    }
}
