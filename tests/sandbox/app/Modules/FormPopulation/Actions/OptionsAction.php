<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;
use Quiote\Validator\IValidatorContainer;

/**
 * An edit page whose view also publishes population options (include_hidden_inputs, skip) the
 * documented way: FormPopulationConfig::merge() + RequestState::publish(). Token and Secret are
 * declared so strict validation keeps them; the options alone must keep them out of the form.
 */
#[Route('/form-population/options', name: 'form_population.options', methods: ['GET', 'POST'], outputType: 'html')]
class OptionsAction extends Action
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
            throw new \RuntimeException('OptionsAction requires an initialized Action context.');
        }
        $validationManager = $initContext->getValidationManager();
        if (!$validationManager instanceof IValidatorContainer) {
            throw new \RuntimeException('OptionsAction requires an IValidatorContainer validation manager.');
        }

        $builder = ValidatorBuilder::on($validationManager, $context);
        $builder->string('Name', required: true)
            ->minLength(3)
            ->error('Name must be at least 3 characters long.');
        $builder->string('Token', required: false);
        $builder->string('Secret', required: false);
    }
}
