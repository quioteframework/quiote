<?php

namespace Sandbox;

use Agavi\Action\AgaviAction;

class TestAction extends AgaviAction 
{
    public function execute(\Agavi\Request\AgaviRequestDataHolder $rd)
    {
        return 'Success';
    }
}
