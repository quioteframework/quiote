<?php
namespace App\Modules\Foo\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class BarView extends View
{
    // Base abstract execute() must be implemented. For HTML output we prefer executeHtml();
    // returning null here ensures the specific output-type method is used when available.
    public function execute(WebRequest $request)
    {
        return null; // fall back to executeHtml() selection
    }

    public function executeHtml(WebRequest $request)
    {
        return 'CONTENT';
    }
}
