<?php
namespace Sandbox\Modules\Snapshot\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class SnapshotActionSuccessView extends AgaviView
{
    public function execute(AgaviWebRequest $rd)
    {
        return 'SNAPSHOT_OK';
    }
}
