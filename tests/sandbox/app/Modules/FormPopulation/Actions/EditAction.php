<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;
use Quiote\Validator\IValidatorContainer;

/**
 * An edit page: the GET shows the stored record in the form, and a POST that fails validation
 * shows it again with what the user typed. Dispatched through the "form-population-test" context
 * (Config/factories.xml).
 */
#[Route('/form-population/edit', name: 'form_population.edit', methods: ['GET', 'POST'], outputType: 'html')]
class EditAction extends Action
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

    public function executeWrite(WebRequest $rd): string
    {
        return 'Input';
    }

    public function handleError(WebRequest $rd): string
    {
        return 'Input';
    }

    public function registerWriteValidators(): void
    {
        $initContext = $this->getInitContext();
        $context = $this->getContext();
        if ($initContext === null || $context === null) {
            throw new \RuntimeException('EditAction requires an initialized Action context.');
        }
        $validationManager = $initContext->getValidationManager();
        if (!$validationManager instanceof IValidatorContainer) {
            throw new \RuntimeException('EditAction requires an IValidatorContainer validation manager.');
        }

        ValidatorBuilder::on($validationManager, $context)
            ->string('Name', required: true)
            ->minLength(3)
            ->error('Name must be at least 3 characters long.');
    }
}
