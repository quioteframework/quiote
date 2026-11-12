<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

/** OptionsInputView's form and values without include_hidden_inputs/skip. */
class OptionsControlInputView extends View
{
    public function execute(WebRequest $rd): string
    {
        return $this->executeHtml($rd);
    }

    public function executeHtml(WebRequest $rd): string
    {
        $this->populateForm('record', OptionsInputView::VALUES);

        return '<!DOCTYPE html><html><body>' . OptionsInputView::FORM . '</body></html>';
    }
}
