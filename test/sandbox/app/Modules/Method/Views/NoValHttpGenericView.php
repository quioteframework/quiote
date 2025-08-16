<?php
namespace Sandbox\Modules\Method\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class NoValHttpGenericView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd){ return '<v>NOVAL_GENERIC</v>'; }
}
