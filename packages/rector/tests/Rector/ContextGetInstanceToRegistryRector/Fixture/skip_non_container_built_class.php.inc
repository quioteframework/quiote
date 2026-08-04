<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class NothingConstructsThis
{
    public function run(): mixed
    {
        // Nothing would supply the injected registry.
        return Context::getInstance('web')->getName();
    }
}
