<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Action\Action;
use Quiote\Context;

final class ReachesNamedProfiles extends Action
{
    public function executeRead(): array
    {
        return [
            Context::getInstance('web')->getName(),
            Context::getInstance()->getName(),
        ];
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\ContextRegistry;
use Quiote\Action\Action;
use Quiote\Context;

final class ReachesNamedProfiles extends Action
{
    public function __construct(private readonly ContextRegistry $contextRegistry)
    {
    }
    public function executeRead(): array
    {
        return [
            $this->contextRegistry->get('web')->getName(),
            $this->contextRegistry->get()->getName(),
        ];
    }
}

?>
