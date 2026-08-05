<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextResidueReporter\Source;

final class ForeignContextValue
{
    public function isValid(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'not-a-quiote-context';
    }
}
