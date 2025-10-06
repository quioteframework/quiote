<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class CacheComplexErrorView extends AgaviView
{
    public function execute(AgaviWebRequest $rd){ return '<div>COMPLEX_ERROR</div>'; }
}
