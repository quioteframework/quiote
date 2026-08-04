<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class HasAnOptionalConstructorParam extends Action
{
    /** @param array<int|string, mixed> $parameters */
    public function __construct(private readonly array $parameters = [])
    {
    }

    public function run(Context $context): string
    {
        return $context->getRouting()->gen('order.show');
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Routing\Routing;
use Quiote\Action\Action;
use Quiote\Context;

final class HasAnOptionalConstructorParam extends Action
{
    /** @param array<int|string, mixed> $parameters */
    public function __construct(private readonly Routing $routing, private readonly array $parameters = [])
    {
    }

    public function run(Context $context): string
    {
        return $this->routing->gen('order.show');
    }
}

?>
