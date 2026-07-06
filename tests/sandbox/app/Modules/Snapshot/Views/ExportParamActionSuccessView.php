<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class ExportParamActionSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'EXPORTED:' . $rd->getParameter('exported', 'MISSING');
    }
}
