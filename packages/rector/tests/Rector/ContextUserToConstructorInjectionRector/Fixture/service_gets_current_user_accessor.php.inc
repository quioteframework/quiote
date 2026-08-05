<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Service\Service;

final class ReadsUserInService extends Service
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

use Quiote\User\CurrentUser;
use Quiote\Service\Service;

final class ReadsUserInService extends Service
{
    public function __construct(\Quiote\Context $context, private readonly CurrentUser $currentUser)
    {
        parent::__construct($context);
    }
    public function run(): string
    {
        return $this->currentUser->get()->getAttribute('name');
    }
}

?>
