<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Rector\Tests\Rector\ContextUserToConstructorInjectionRector\Source\AppBaseAction;

final class AlreadyHasAUserProperty extends AppBaseAction
{
    private ?string $user = null;

    public function run(): string
    {
        return $this->getContext()->getUser()->getAttribute('name') . $this->user;
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\User\User;
use Quiote\Rector\Tests\Rector\ContextUserToConstructorInjectionRector\Source\AppBaseAction;

final class AlreadyHasAUserProperty extends AppBaseAction
{
    private ?string $user = null;
    public function __construct(private readonly User $user2)
    {
    }

    public function run(): string
    {
        return $this->user2->getAttribute('name') . $this->user;
    }
}

?>
