<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest ;

class ControllerTestSuccessView extends AgaviView
{
    public function execute(AgaviWebRequest $rd)
    {
        return 'Success';
    }
}

?>