<?php
namespace Sandbox\Modules\Method\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class MethodHttpPostErrorView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd){ return '<v>POST_ERROR</v>'; }
}
