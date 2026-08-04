<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;
use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\TagService;

final class SingleService extends Action
{
    public function run(Context $context): string
    {
        return $context->getService(TagService::class)->tag();
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;
use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\TagService;

final class SingleService extends Action
{
    public function __construct(private readonly TagService $tagService)
    {
    }
    public function run(Context $context): string
    {
        return $this->tagService->tag();
    }
}

?>
