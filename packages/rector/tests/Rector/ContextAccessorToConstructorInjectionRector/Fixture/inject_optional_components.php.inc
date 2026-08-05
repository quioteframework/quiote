<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class UsesOptionalComponents extends Action
{
    public function run(Context $context): mixed
    {
        // Reached through ?-> because the accessor answered null in a context configuring neither.
        // The operator survives the rewrite; what it guards against cannot happen any more, because
        // an absent component is bound to a factory that says so rather than autowired empty.
        $locale = $context->getTranslationManager()?->getCurrentLocaleIdentifier();
        $database = $context->getDatabaseManager()?->getDatabase();

        return [$locale, $database];
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Translation\TranslationManager;
use Quiote\Database\DatabaseManager;
use Quiote\Action\Action;
use Quiote\Context;

final class UsesOptionalComponents extends Action
{
    public function __construct(private readonly TranslationManager $translationManager, private readonly DatabaseManager $databaseManager)
    {
    }
    public function run(Context $context): mixed
    {
        // Reached through ?-> because the accessor answered null in a context configuring neither.
        // The operator survives the rewrite; what it guards against cannot happen any more, because
        // an absent component is bound to a factory that says so rather than autowired empty.
        $locale = $this->translationManager?->getCurrentLocaleIdentifier();
        $database = $this->databaseManager?->getDatabase();

        return [$locale, $database];
    }
}

?>
