<?php

use Agavi\View\AgaviView;
use Agavi\Renderer\AgaviRenderer;
use Agavi\Request\AgaviRequestDataHolder;

class ControllerTests_ControllerTestErrorView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd)
    {
        return 'Error';
    }
}

?>