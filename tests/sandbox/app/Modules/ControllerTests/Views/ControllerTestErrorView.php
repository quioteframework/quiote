<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class ControllerTestErrorView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'Error';
    }
}

?>