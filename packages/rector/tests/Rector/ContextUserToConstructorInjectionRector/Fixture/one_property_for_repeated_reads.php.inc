<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\View\View;

final class ReadsUserTwiceInView extends View
{
    public function run(): string
    {
        $first = $this->getContext()->getUser()->getAttribute('first');
        $second = $this->getContext()->getUser()->getAttribute('second');

        return $first . $second;
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\User\User;
use Quiote\View\View;

final class ReadsUserTwiceInView extends View
{
    public function __construct(private readonly User $user)
    {
    }
    public function run(): string
    {
        $first = $this->user->getAttribute('first');
        $second = $this->user->getAttribute('second');

        return $first . $second;
    }
}

?>
