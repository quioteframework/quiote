<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class SnapshotActionSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'SNAPSHOT_OK';
    }
}
