<?php
namespace Sandbox\Modules\Method\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class MethodHttpPostView extends AgaviView
{
    public function execute(AgaviWebRequest $rd){ return '<v>POST_OK</v>'; }
}
