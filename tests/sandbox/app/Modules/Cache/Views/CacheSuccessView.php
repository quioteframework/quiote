<?php
namespace Sandbox\Modules\Cache\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class CacheSuccessView extends View
{
    public function executeHtml(WebRequest $rd)
    {
        return '<div>CACHE_HTML</div>';
    }
    public function executeJson(WebRequest $rd)
    {
        return json_encode(['status'=>'ok','type'=>'json','variant'=>'cache']);
    }
    public function executeXml(WebRequest $rd)
    {
        return '<cache status="ok" type="xml" />';
    }
    public function execute(WebRequest $rd)
    {
        // Fallback (no specific output type)
        return 'CACHE_FALLBACK';
    }
}