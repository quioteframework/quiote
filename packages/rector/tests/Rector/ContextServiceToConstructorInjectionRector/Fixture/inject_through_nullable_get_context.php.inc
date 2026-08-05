<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\TagService;

final class NullableGetContextService extends Action
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

use Quiote\Action\Action;
use Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector\Source\TagService;

final class NullableGetContextService extends Action
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
