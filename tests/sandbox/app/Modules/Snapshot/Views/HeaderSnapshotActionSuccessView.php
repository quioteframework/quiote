<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class HeaderSnapshotActionSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'HEADER_OK';
    }
}
