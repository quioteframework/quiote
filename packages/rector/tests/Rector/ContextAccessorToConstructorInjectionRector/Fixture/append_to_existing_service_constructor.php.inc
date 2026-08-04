<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;
use Quiote\Service\Service;

final class AlreadyHasAConstructor extends Service
{
    public function __construct(Context $context, private readonly string $prefix = '')
    {
        parent::__construct($context);
    }

    public function url(string $id): string
    {
        return $this->prefix . $this->getContext()->getRouting()->gen('order.show', ['id' => $id]);
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Routing\Routing;
use Quiote\Context;
use Quiote\Service\Service;

final class AlreadyHasAConstructor extends Service
{
    public function __construct(Context $context, private readonly Routing $routing, private readonly string $prefix = '')
    {
        parent::__construct($context);
    }

    public function url(string $id): string
    {
        return $this->prefix . $this->routing->gen('order.show', ['id' => $id]);
    }
}

?>
