<?php

namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Validator\ValidationManager;
use Sandbox\Modules\Snapshot\Validators\ExportingSlotValidator;

/**
 * Registers a validator that exports a parameter (see ExportingSlotValidator),
 * then reads that parameter directly inside execute() -- reproducing
 * SlotDispatcher's non-simple path, where $rd is captured before validate()
 * runs. WebRequest is immutable, so the validator's export() only replaces
 * ValidationManager's own copy of the request; without a re-fetch after
 * validate() (mirroring the one ActionExecutor performs after execute()),
 * $rd would stay the pre-validation instance and getParameter() would throw
 * UnvalidatedParameterAccessException for a parameter that was, in fact,
 * validated and exported.
 *
 * Deliberately NOT isSimple(): isSimple() means "skip execute*() entirely" --
 * execute() would never run, defeating the point of this test.
 */
class ExportViaValidatorAction extends Action
{
    public static ?string $observedValue = null;

    #[\Override]
    public function isSimple(): bool
    {
        return false;
    }

    #[\Override]
    public function isSecure(): bool
    {
        return false;
    }

    public function registerReadValidators(): void
    {
        $vm = $this->getInitContext()?->getValidationManager();
        if (!$vm instanceof ValidationManager) {
            throw new \RuntimeException('Expected a ValidationManager instance from getInitContext()->getValidationManager()');
        }
        $vm->createValidator(ExportingSlotValidator::class, [], [], ['name' => 'exportingValidator']);
    }

    public function execute(WebRequest $rd): string
    {
        $exported = $rd->getParameter('ValidatorExported');
        self::$observedValue = \is_string($exported) ? $exported : null;

        return 'Success';
    }

    #[\Override]
    public function handleError(WebRequest $rd): string
    {
        return 'Error';
    }

    #[\Override]
    public function getDefaultViewName(): string
    {
        return 'Success';
    }
}
