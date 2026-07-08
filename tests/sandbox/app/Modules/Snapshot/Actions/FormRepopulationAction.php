<?php
namespace Sandbox\Modules\Snapshot\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;

/**
 * Regression fixture for the v1.0.0 release "sticky form" bug: a field with
 * TWO validators (length + not-numeric), where the submitted value passes
 * one and fails the other, used to disappear entirely from the re-rendered
 * error view -- WebRequest::pruneParametersToValidated() scrubs a value that
 * fails even one of several validators registered against the same name,
 * even though the name stays whitelisted. See
 * Quiote\Validator\ValidationManager::getRawParameterSnapshot() and
 * ValidationMiddleware's FormPopulationEngine wiring for the fix.
 */
class FormRepopulationAction extends Action
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
            throw new \RuntimeException('FormRepopulationAction requires an initialized Action context.');
        }

        $v = ValidatorBuilder::on(
            $initContext->getValidationManager(),
            $context,
        );
        $v->string('name', required: true)
            ->minLength(3)
            ->maxLength(7)
            ->error('Name must be between 3 and 7 characters long.');
        $v->regex('name', '/^[0-9]+$/', shouldMatch: false, required: true)
            ->error('Name must not be numeric.');
    }
}
