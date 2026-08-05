<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class ConditionallyRegisteredAccessors
{
    public function run(Context $context): mixed
    {
        // Nothing constructs this class, so there is no constructor to inject into whatever the
        // accessors are. Left for the residue reporter.
        $locale = $context->getTranslationManager()?->getCurrentLocaleIdentifier();
        $database = $context->getDatabaseManager()?->getDatabase();

        return [$locale, $database];
    }
}
