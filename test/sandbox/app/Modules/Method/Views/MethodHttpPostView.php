<?php
namespace Sandbox\Modules\Method\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class MethodHttpPostView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd){ return '<v>POST_OK</v>'; }
}
