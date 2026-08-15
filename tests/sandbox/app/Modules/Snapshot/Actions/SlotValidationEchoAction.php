<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;
use Quiote\Validator\IValidatorContainer;

/**
 * Regression fixture: a non-simple action, dispatched only as a slot, whose
 * error view reads the validation manager off its init context via
 * View::returnProblemDetailsFromValidationIncidents(). Exercises whether
 * SlotDispatcher hands ViewFactory::create() the live ValidationManager
 * (populated with this request's errors) instead of letting it fall through
 * to a fresh transient instance from the container.
 */
class SlotValidationEchoAction extends Action
{
    #[\Override]
    public function isSimple(): bool { return false; }

    public function executeWrite(WebRequest $rd): string
    {
        return 'Success';
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
            throw new \RuntimeException('SlotValidationEchoAction requires an initialized Action context.');
        }

        $validationManager = $initContext->getValidationManager();
        if (!$validationManager instanceof IValidatorContainer) {
            throw new \RuntimeException('SlotValidationEchoAction requires an IValidatorContainer validation manager.');
        }

        $v = ValidatorBuilder::on(
            $validationManager,
            $context,
        );
        $v->string('name', required: true)
            ->minLength(3)
            ->error('Name must be at least 3 characters long.');
    }
}
