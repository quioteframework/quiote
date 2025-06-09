<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class ControllerTestErrorView extends AgaviView
{
    public function execute(AgaviRequestDataHolder $rd)
    {
        return 'Error';
    }
}

?>