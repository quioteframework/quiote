<?php
namespace Sandbox\Modules\Method\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class MethodHttpPostView extends View
{
    public function execute(WebRequest $rd){ return '<v>POST_OK</v>'; }
}
