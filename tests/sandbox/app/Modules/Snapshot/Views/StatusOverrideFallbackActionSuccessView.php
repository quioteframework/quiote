<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class StatusOverrideFallbackActionSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'FALLBACK_TEAPOT';
    }
}
