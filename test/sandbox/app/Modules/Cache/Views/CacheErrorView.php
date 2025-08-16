<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class CacheErrorView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd){ return '<div>COMPLEX_ERROR</div>'; }
}

