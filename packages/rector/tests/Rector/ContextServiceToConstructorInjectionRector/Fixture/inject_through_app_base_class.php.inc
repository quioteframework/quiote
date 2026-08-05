<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\AppBaseAction;
use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\TagService;

final class ServiceThroughAppBaseClass extends AppBaseAction
{
    public function run(): string
    {
        return $this->getContext()->getService(TagService::class)->tag();
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\AppBaseAction;
use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\TagService;

final class ServiceThroughAppBaseClass extends AppBaseAction
{
    public function __construct(private readonly TagService $tagService)
    {
    }
    public function run(): string
    {
        return $this->tagService->tag();
    }
}

?>
