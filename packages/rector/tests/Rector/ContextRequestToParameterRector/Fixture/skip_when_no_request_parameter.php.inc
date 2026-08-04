<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class HasNoRequestParameter
{
    public function run(Context $context): mixed
    {
        // RequestState::current() is the target for these; inventing a variable name here would
        // produce code that does not compile.
        return $context->getRequest()->getParameter('id');
    }
}
