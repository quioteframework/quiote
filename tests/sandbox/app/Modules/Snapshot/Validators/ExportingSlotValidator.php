<?php

namespace Sandbox\Modules\Snapshot\Validators;

use Quiote\Validator\Validator;

/**
 * Always succeeds and exports a constant value -- exercises the export() path
 * (see ExportViaValidatorAction) without needing a real argument to validate.
 */
class ExportingSlotValidator extends Validator
{
    protected function validate(): bool
    {
        $this->export('from-validator', 'ValidatorExported');

        return true;
    }
}
