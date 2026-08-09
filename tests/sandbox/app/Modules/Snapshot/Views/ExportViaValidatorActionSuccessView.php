<?php

namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class ExportViaValidatorActionSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        $exported = $rd->getParameter('ValidatorExported', 'MISSING');

        return 'VIEW:' . (\is_string($exported) ? $exported : 'MISSING');
    }
}
