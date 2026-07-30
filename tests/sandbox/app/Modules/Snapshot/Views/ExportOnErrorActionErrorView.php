<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;

class ExportOnErrorActionErrorView extends View
{
    public function execute(WebRequest $rd)
    {
        $value = $rd->getParameter('error_export', 'MISSING');
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('ExportOnErrorActionErrorView expects a stringable "error_export" parameter.');
        }
        return 'ERROR_EXPORTED:' . $value;
    }
}
