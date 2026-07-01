<?php

namespace Sandbox;

use Quiote\Action\Action;

class TestAction extends Action 
{
    public function execute(\Quiote\Request\WebRequest $rd)
    {
        return 'Success';
    }
}
