<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class ParamSnapshotActionSuccessView extends View
{
    public function execute(WebRequest $rd): string
    {
        return 'PARAM_OK';
    }
}
