<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class PlainInputView extends View
{
    public function execute(WebRequest $rd): string
    {
        return $this->executeHtml($rd);
    }

    public function executeHtml(WebRequest $rd): string
    {
        return '<!DOCTYPE html><html><body>'
            . '<form id="plain" action="/form-population/plain" method="get"><input type="text" name="Name"></form>'
            . '</body></html>';
    }
}
