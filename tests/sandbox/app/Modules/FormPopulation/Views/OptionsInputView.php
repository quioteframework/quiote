<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Views;

use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Util\FormPopulationConfig;
use Quiote\View\View;

/**
 * The edit form with a hidden input and a field the view asks population to skip. Both names are
 * in the populate values, so only include_hidden_inputs=false and skip keep them unfilled.
 * OptionsControlInputView renders the same form without the options, proving they would be filled.
 */
class OptionsInputView extends View
{
    public const string FORM = '<form id="record" action="/form-population/options" method="post">'
        . '<input type="text" name="Name">'
        . '<input type="hidden" name="Token" value="rendered-token">'
        . '<input type="text" name="Secret">'
        . '</form>';

    /** @var array<string, string> */
    public const array VALUES = ['Name' => 'Stored name', 'Token' => 'stored-token', 'Secret' => 'stored-secret'];

    public function execute(WebRequest $rd): string
    {
        return $this->executeHtml($rd);
    }

    public function executeHtml(WebRequest $rd): string
    {
        $this->populateForm('record', self::VALUES);

        $context = $this->getContext();
        if ($context === null) {
            throw new \RuntimeException('OptionsInputView requires an initialized context.');
        }
        $requestState = $context->getContainer()->get(RequestState::class);
        $updated = FormPopulationConfig::merge($requestState->current(), ['include_hidden_inputs' => false, 'skip' => ['Secret']]);
        if (!$updated instanceof WebRequest) {
            throw new \RuntimeException('FormPopulationConfig::merge() did not return a WebRequest.');
        }
        $requestState->publish($updated);

        return '<!DOCTYPE html><html><body>' . self::FORM . '</body></html>';
    }
}
