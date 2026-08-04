<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Service\Service;

final class ReachesRoutingFromAService extends Service
{
    public function url(string $id): string
    {
        return $this->getContext()->getRouting()->gen('order.show', ['id' => $id]);
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Routing\Routing;
use Quiote\Service\Service;

final class ReachesRoutingFromAService extends Service
{
    public function __construct(\Quiote\Context $context, private readonly Routing $routing)
    {
        parent::__construct($context);
    }
    public function url(string $id): string
    {
        return $this->routing->gen('order.show', ['id' => $id]);
    }
}

?>
