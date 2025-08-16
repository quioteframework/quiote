<?php
namespace Sandbox\Modules\Snapshot\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class SnapshotActionSuccessView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd)
    {
        return 'SNAPSHOT_OK';
    }
}
