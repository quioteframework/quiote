<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class NothingConstructsThis
{
    public function run(Context $context): mixed
    {
        // Not an Action/View/Service/Validator, so nothing would ever supply the injected
        // RequestState -- the class would fatal on construction.
        return $context->getRequest()->getParameter('id');
    }
}
