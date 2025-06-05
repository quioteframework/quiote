<?php

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class ControllerTests_ControllerTestSuccessView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd)
    {
        return 'Success';
    }
}

?>