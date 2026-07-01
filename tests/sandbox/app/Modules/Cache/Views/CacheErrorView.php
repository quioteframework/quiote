<?php
namespace Sandbox\Modules\Cache\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class CacheErrorView extends View
{
    public function execute(WebRequest $rd){ return '<div>COMPLEX_ERROR</div>'; }
}

