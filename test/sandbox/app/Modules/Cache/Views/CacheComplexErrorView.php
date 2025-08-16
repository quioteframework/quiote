<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class CacheComplexErrorView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd){ return '<div>COMPLEX_ERROR</div>'; }
}
