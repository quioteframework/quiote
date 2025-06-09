<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Renderer\AgaviRenderer;

class ControllerTestAction extends AgaviAction
{
    public function execute(?AgaviRenderer $renderer = null)
    {
        return 'Success';
    }
}
?>