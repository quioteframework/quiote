<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;
use Quiote\Request\WebRequest;

final class ReadsAndPublishes extends Action
{
    public function executeRead(Context $context, WebRequest $rd): mixed
    {
        $id = $context->getRequest()->getParameter('id');
        $context->setRequest($rd);

        return $id;
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Request\RequestState;
use Quiote\Action\Action;
use Quiote\Context;
use Quiote\Request\WebRequest;

final class ReadsAndPublishes extends Action
{
    public function __construct(private readonly RequestState $requestState)
    {
    }
    public function executeRead(Context $context, WebRequest $rd): mixed
    {
        $id = $this->requestState->current()->getParameter('id');
        $this->requestState->publish($rd);

        return $id;
    }
}

?>
