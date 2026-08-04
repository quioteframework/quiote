<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class RepeatedAccessor extends Action
{
    public function run(Context $context): string
    {
        return $context->getRouting()->gen('a') . $context->getRouting()->gen('b');
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Routing\Routing;
use Quiote\Action\Action;
use Quiote\Context;

final class RepeatedAccessor extends Action
{
    public function __construct(private readonly Routing $routing)
    {
    }
    public function run(Context $context): string
    {
        return $this->routing->gen('a') . $this->routing->gen('b');
    }
}

?>
