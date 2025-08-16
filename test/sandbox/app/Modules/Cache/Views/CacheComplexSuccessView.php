<?php
namespace Sandbox\Modules\Cache\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class CacheComplexSuccessView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd){ return '<div>COMPLEX_OK</div>'; }
}
