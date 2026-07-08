<?php

namespace Sandbox;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class TestSuccessView extends View
{
    public function execute(WebRequest $rd): void
    {
    }

    public function executeHtml(WebRequest $rd): void
    {
        $this->setAttribute('title', 'Test Page');
    }
}
