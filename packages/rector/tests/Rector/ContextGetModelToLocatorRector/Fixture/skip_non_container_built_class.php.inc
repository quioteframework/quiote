<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class NothingConstructsThis
{
    public function run(Context $context): mixed
    {
        // Nothing would supply the injected locator, so the class would fatal on construction.
        return $context->getModel('Order');
    }
}
