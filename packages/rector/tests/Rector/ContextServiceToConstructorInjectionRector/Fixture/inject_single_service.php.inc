<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;
use Quiote\Rector\Tests\Fixture\Source\TagService;

final class SingleService
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

use Quiote\Context;
use Quiote\Rector\Tests\Fixture\Source\TagService;

final class SingleService
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
