<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class LocatesModels extends Action
{
    public function executeRead(Context $context): array
    {
        return [
            $context->getModel('Order'),
            $context->getModel('Order', 'Sales'),
            $context->getModel('Order', 'Sales', ['id' => 1]),
        ];
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Model\ModelLocator;
use Quiote\Action\Action;
use Quiote\Context;

final class LocatesModels extends Action
{
    public function __construct(private readonly ModelLocator $modelLocator)
    {
    }
    public function executeRead(Context $context): array
    {
        return [
            $this->modelLocator->get('Order'),
            $this->modelLocator->get('Order', 'Sales'),
            $this->modelLocator->get('Order', 'Sales', ['id' => 1]),
        ];
    }
}

?>
