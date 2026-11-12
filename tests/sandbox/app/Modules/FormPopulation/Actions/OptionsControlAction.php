<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;

/**
 * Control for OptionsAction: the same form and values, no population options. Shows that the
 * hidden input and the skipped field would be filled if the options were not honoured.
 */
#[Route('/form-population/options-control', name: 'form_population.options_control', methods: ['GET'], outputType: 'html')]
class OptionsControlAction extends Action
{
    #[\Override]
    public function isSimple(): bool
    {
        return false;
    }

    public function executeRead(WebRequest $rd): string
    {
        return 'Input';
    }
}
