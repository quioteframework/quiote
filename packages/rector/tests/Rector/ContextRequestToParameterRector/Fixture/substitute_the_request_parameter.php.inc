<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;
use Quiote\Request\WebRequest;

final class ReadsAParameter
{
    public function executeRead(Context $context, WebRequest $rd): mixed
    {
        return $context->getRequest()->getParameter('id');
    }
}

?>
-----
<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;
use Quiote\Request\WebRequest;

final class ReadsAParameter
{
    public function executeRead(Context $context, WebRequest $rd): mixed
    {
        return $rd->getParameter('id');
    }
}

?>
