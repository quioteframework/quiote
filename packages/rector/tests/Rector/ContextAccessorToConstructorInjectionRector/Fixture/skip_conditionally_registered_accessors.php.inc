<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class ConditionallyRegisteredAccessors
{
    public function run(Context $context): mixed
    {
        // Both are null when their subsystem is disabled, and both classes are instantiable with
        // no required arguments -- so injecting them would autowire a fresh, uninitialized one and
        // rewrite the ?-> guard away. Left for the residue reporter.
        $locale = $context->getTranslationManager()?->getCurrentLocaleIdentifier();
        $database = $context->getDatabaseManager()?->getDatabase();

        return [$locale, $database];
    }
}
