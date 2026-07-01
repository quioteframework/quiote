<?php
namespace Sandbox\Modules\Method\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest ;

class NoValHttpGenericView extends View
{
    public function execute(WebRequest $rd){ return '<v>NOVAL_GENERIC</v>'; }
}
