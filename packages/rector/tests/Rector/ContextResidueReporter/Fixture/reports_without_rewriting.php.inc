<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class HasEveryResidueShape
{
    public function notContainerBuilt(Context $context): string
    {
        // The class is outside the four container-built hierarchies, so nothing can be injected here.
        return $context->getUser()->getAttribute('name');
    }

    /**
     * Shaped exactly like a Context call -- a variable named $context -- and definitely not one. A
     * core class on purpose: PHPStan resolves it wherever this fixture runs, whereas this test
     * directory's own Source classes are not reflectable inside the Rector harness, which would land
     * the site in `unresolved-receiver` instead and prove nothing about the distinction.
     */
    public function foreignReceiver(): string
    {
        $context = new \Exception('a span context, a browser context, a render context');

        return $context->getMessage();
    }

    public function notAnAccessor(Context $context): void
    {
        // A real Context, and a method it does not declare.
        $context->flushRequestState();
    }

    /**
     * The application shape that motivated telling this apart from a foreign receiver: an untyped
     * parameter defaulting to null, filled in from the context when the caller passed nothing. The
     * type is `mixed`, so nobody can say from here whether this is work.
     */
    public function unresolvedReceiver($context = null): string
    {
        if (!isset($context)) {
            $context = $this->getContext();
        }

        return $context->getRouting()->gen('order.show');
    }
}
