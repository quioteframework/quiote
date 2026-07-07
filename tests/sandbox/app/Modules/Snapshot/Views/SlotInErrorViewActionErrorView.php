<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

/**
 * Renders a slot from within an error view — see
 * SlotInErrorViewAction for what this guards against.
 */
class SlotInErrorViewActionErrorView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'SLOT:' . $this->renderSlot('Cache', 'Cache');
    }
}
