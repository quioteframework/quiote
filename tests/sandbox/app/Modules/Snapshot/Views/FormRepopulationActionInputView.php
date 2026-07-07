<?php
namespace Sandbox\Modules\Snapshot\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

/**
 * Plain, static form markup with no value= attribute on purpose --
 * FormPopulationEngine is expected to fill it in from the raw pre-prune
 * snapshot after a validation failure (see FormRepopulationAction).
 */
class FormRepopulationActionInputView extends View
{
    public function execute(WebRequest $rd)
    {
        return $this->executeHtml($rd);
    }

    public function executeHtml(WebRequest $rd)
    {
        return '<!DOCTYPE html><html><body><form action="/form-repopulation" method="post">'
            . '<input type="text" name="name">'
            . '</form></body></html>';
    }
}
