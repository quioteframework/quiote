<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Rector\Tests\Rector\ContextUserToConstructorInjectionRector\Source\AppBaseAction;

final class ReadsUserInAction extends AppBaseAction
{
    public function run(): string
    {
        return $this->getContext()->getUser()->getAttribute('name');
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\User\User;
use Quiote\Rector\Tests\Rector\ContextUserToConstructorInjectionRector\Source\AppBaseAction;

final class ReadsUserInAction extends AppBaseAction
{
    public function __construct(private readonly User $user)
    {
    }
    public function run(): string
    {
        return $this->user->getAttribute('name');
    }
}

?>
