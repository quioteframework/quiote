<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class SlotValidationEchoActionSuccessView extends View
{
    public function execute(WebRequest $rd): string
    {
        return 'Success';
    }
}
