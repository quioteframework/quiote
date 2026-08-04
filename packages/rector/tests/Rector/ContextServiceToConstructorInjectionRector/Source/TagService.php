<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source;

final class TagService
{
    public function tag(): string
    {
        return 'tagged';
    }
}
