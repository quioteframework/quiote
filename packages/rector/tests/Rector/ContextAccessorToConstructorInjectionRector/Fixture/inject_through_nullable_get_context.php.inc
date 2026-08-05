<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;

final class UsesAccessorThroughNullableGetContext extends Action
{
    public function run(): string
    {
        return $this->getContext()->getRouting()->gen('order.show');
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Routing\Routing;
use Quiote\Action\Action;

final class UsesAccessorThroughNullableGetContext extends Action
{
    public function __construct(private readonly Routing $routing)
    {
    }
    public function run(): string
    {
        return $this->routing->gen('order.show');
    }
}

?>
