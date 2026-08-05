<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class PlainHelper
{
    public function run(Context $context): string
    {
        return $context->getUser()->getAttribute('name');
    }
}

?>
