<?php

namespace Sandbox;

use Agavi\View\AgaviView;

class TestSuccessView extends AgaviView
{
    public function executeHtml(\Agavi\Request\AgaviRequestDataHolder $rd)
    {
        $this->setTitle('Test Page');
    }
}
