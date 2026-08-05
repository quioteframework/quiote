<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;
use Quiote\Rector\Tests\Rector\ContextResidueReporter\Source\SpanLike;

final class HasEveryResidueShape
{
    public function notContainerBuilt(Context $context): string
    {
        // The class is outside the four container-built hierarchies, so nothing can be injected here.
        return $context->getUser()->getAttribute('name');
    }

    public function foreignReceiver(SpanLike $span): bool
    {
        // Shaped exactly like a Context call. It is not one.
        return $span->getContext()->isValid();
    }

    public function notAnAccessor(Context $context): void
    {
        // A real Context, and a method it does not declare.
        $context->flushRequestState();
    }
}
