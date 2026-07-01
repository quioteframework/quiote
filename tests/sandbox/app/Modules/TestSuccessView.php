<?php

namespace Sandbox;

use Quiote\View\View;

class TestSuccessView extends View
{
    public function executeHtml(\Quiote\Request\WebRequest $rd)
    {
        $this->setTitle('Test Page');
    }
}
