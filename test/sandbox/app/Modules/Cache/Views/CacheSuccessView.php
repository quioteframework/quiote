<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class CacheSuccessView extends AgaviView
{
    public function executeHtml(AgaviRequestDataHolder $rd)
    {
        return '<div>CACHE_HTML</div>';
    }
    public function executeJson(AgaviRequestDataHolder $rd)
    {
        return json_encode(['status'=>'ok','type'=>'json','variant'=>'cache']);
    }
    public function executeXml(AgaviRequestDataHolder $rd)
    {
        return '<cache status="ok" type="xml" />';
    }
    public function execute(AgaviRequestDataHolder $rd)
    {
        // Fallback (no specific output type)
        return 'CACHE_FALLBACK';
    }
}