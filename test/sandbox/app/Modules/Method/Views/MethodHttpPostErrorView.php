<?php
namespace Sandbox\Modules\Method\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class MethodHttpPostErrorView extends AgaviView
{
    public function execute(AgaviWebRequest $rd){ return '<v>POST_ERROR</v>'; }
}
