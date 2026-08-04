<?php

namespace Quiote\Rector\Tests\Fixture;

use PHPUnit\Framework\MockObject\MockObject;
use Quiote\Context;

final class MockedContextSetup
{
    /** @param Context&MockObject $context */
    public function configure(MockObject $context): void
    {
        // $context really is a Context here (MockObject&Context), so type
        // resolution alone does not exclude this -- only the method name does.
        $context->method('getName')->willReturn('web');
    }
}
