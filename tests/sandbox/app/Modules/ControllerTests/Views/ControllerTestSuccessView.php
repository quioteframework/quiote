<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class ControllerTestSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        return 'Success';
    }
}

?>