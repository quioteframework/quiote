<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextGetInstanceToRegistryRector\Source;

/** An unrelated singleton, to prove the rule matches on the class and not the method name. */
final class NotAContext
{
    public static function getInstance(): self
    {
        return new self();
    }
}
