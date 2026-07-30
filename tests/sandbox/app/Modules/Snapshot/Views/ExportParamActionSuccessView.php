<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class ExportParamActionSuccessView extends View
{
    public function execute(WebRequest $rd)
    {
        $value = $rd->getParameter('exported', 'MISSING');
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('ExportParamActionSuccessView expects a stringable "exported" parameter.');
        }
        return 'EXPORTED:' . $value;
    }
}
