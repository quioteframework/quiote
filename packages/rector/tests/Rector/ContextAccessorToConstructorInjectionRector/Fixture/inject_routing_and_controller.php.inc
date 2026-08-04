<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class UsesProcessLifetimeAccessors extends Action
{
    public function run(Context $context): string
    {
        $url = $context->getRouting()->gen('order.show');
        $name = $context->getController()->getOutputType()->getName();

        return $url . $name;
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Routing\Routing;
use Quiote\Controller\Controller;
use Quiote\Action\Action;
use Quiote\Context;

final class UsesProcessLifetimeAccessors extends Action
{
    public function __construct(private readonly Routing $routing, private readonly Controller $controller)
    {
    }
    public function run(Context $context): string
    {
        $url = $this->routing->gen('order.show');
        $name = $this->controller->getOutputType()->getName();

        return $url . $name;
    }
}

?>
