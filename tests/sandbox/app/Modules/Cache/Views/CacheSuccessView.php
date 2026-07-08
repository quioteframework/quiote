<?php
namespace Sandbox\Modules\Cache\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class CacheSuccessView extends View
{
    public function executeHtml(WebRequest $rd): string
    {
        return '<div>CACHE_HTML</div>';
    }
    public function executeJson(WebRequest $rd): string|false
    {
        return json_encode(['status'=>'ok','type'=>'json','variant'=>'cache']);
    }
    public function executeXml(WebRequest $rd): string
    {
        return '<cache status="ok" type="xml" />';
    }
    public function execute(WebRequest $rd): string
    {
        // Fallback (no specific output type)
        return 'CACHE_FALLBACK';
    }
}