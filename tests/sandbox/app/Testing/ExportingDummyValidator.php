<?php

namespace Sandbox\Testing;

use Quiote\Validator\Validator;

/**
 * Like DummyValidator, but exports a value on success -- for exercising the
 * export() propagation path through operator validators (or/and/xor/not).
 */
class ExportingDummyValidator extends Validator
{
    public bool $val_result = true;
    public bool $validated = false;

    protected function validate(): bool
    {
        $this->validated = true;
        if ($this->val_result) {
            $this->export('exported-value', 'ExportedByChild');
        } else {
            $this->throwError();
        }

        return $this->val_result;
    }

    public function clear(): void
    {
        $this->validated = false;
    }
}
