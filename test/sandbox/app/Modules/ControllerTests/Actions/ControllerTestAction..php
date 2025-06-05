<?php

use Agavi\Action\AgaviAction;
use Agavi\Renderer\AgaviRenderer;

class ControllerTests_ControllerTestAction extends AgaviAction
{
    public function execute(?AgaviRenderer $renderer = null)
    {
        return 'Success';
    }
}
?>