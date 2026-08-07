<?php

namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class ExportViaValidatorActionSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'VIEW:' . $rd->getParameter('ValidatorExported', 'MISSING');
    }
}
