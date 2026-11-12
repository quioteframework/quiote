<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;

/**
 * A page with a form whose view asks for no population, so its markup reaches the client as
 * rendered whatever the request carries.
 */
#[Route('/form-population/plain', name: 'form_population.plain', methods: ['GET'], outputType: 'html')]
class PlainAction extends Action
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
