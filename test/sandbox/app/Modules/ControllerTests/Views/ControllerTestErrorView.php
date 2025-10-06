<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class ControllerTestErrorView extends AgaviView
{
    public function execute(AgaviWebRequest $rd)
    {
        return 'Error';
    }
}

?>