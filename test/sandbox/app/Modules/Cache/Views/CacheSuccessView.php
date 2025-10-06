<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class CacheSuccessView extends AgaviView
{
    public function executeHtml(AgaviWebRequest $rd)
    {
        return '<div>CACHE_HTML</div>';
    }
    public function executeJson(AgaviWebRequest $rd)
    {
        return json_encode(['status'=>'ok','type'=>'json','variant'=>'cache']);
    }
    public function executeXml(AgaviWebRequest $rd)
    {
        return '<cache status="ok" type="xml" />';
    }
    public function execute(AgaviWebRequest $rd)
    {
        // Fallback (no specific output type)
        return 'CACHE_FALLBACK';
    }
}