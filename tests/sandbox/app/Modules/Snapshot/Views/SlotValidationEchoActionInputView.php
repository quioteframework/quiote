<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

/**
 * Renders the live validation manager's error messages as JSON, so a test
 * can assert on the actual incident rather than an empty/generic body.
 */
class SlotValidationEchoActionInputView extends View
{
    public function execute(WebRequest $rd): string
    {
        return $this->returnProblemDetailsFromValidationIncidents(title: 'Invalid slot input');
    }
}
