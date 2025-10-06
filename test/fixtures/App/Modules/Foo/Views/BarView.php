<?php
namespace App\Modules\Foo\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest;

class BarView extends AgaviView
{
    // Base abstract execute() must be implemented. For HTML output we prefer executeHtml();
    // returning null here ensures the specific output-type method is used when available.
    public function execute(AgaviWebRequest $request)
    {
        return null; // fall back to executeHtml() selection
    }

    public function executeHtml(AgaviWebRequest $request)
    {
        return 'CONTENT';
    }
}
