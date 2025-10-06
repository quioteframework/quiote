<?php

namespace Sandbox;

use Agavi\Action\AgaviAction;

class TestAction extends AgaviAction 
{
    public function execute(\Agavi\Request\AgaviWebRequest $rd)
    {
        return 'Success';
    }
}
