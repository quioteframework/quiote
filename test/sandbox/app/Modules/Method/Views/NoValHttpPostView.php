<?php
namespace Sandbox\Modules\Method\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class NoValHttpPostView extends AgaviView
{
    public function execute(AgaviWebRequest $rd){ return '<v>NOVAL_POST_OK</v>'; }
}
