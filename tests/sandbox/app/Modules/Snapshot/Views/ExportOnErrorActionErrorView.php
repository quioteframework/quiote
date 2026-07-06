<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class ExportOnErrorActionErrorView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'ERROR_EXPORTED:' . $rd->getParameter('error_export', 'MISSING');
    }
}
