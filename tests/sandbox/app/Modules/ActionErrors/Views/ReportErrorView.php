<?php
declare(strict_types=1);

namespace Sandbox\Modules\ActionErrors\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

/**
 * Renders what its validation manager holds: the errors the action reported, and the arguments
 * validation passed, which are only there when it is the manager validation ran with.
 */
class ReportErrorView extends View
{
    public function execute(WebRequest $rd): string
    {
        return $this->executeHtml($rd);
    }

    public function executeHtml(WebRequest $rd): string
    {
        $report = $this->getInitContext()?->getValidationManager()?->getReport();
        if ($report === null) {
            return '<p>no validation manager</p>';
        }

        $validated = [];
        foreach ($report->getSucceededArguments() as $argument) {
            $validated[] = $argument->getName();
        }

        return '<p>errors: ' . implode('; ', $report->getErrorMessages()) . '</p>'
            . '<p>validated: ' . implode(', ', $validated) . '</p>';
    }
}
