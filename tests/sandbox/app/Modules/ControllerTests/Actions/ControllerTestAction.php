<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Quiote\Action\Action;
use Quiote\Renderer\Renderer;

class ControllerTestAction extends Action
{
    public function execute(?Renderer $renderer = null)
    {
        return 'Success';
    }
}
?>