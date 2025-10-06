<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class CacheComplexSuccessView extends AgaviView
{
    public function execute(AgaviWebRequest $rd){ return '<div>COMPLEX_OK</div>'; }
}
