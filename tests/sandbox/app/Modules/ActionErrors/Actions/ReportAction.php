<?php
declare(strict_types=1);

namespace Sandbox\Modules\ActionErrors\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;
use Quiote\Validator\IValidatorContainer;
use Quiote\Validator\ValidationError;
use Quiote\Validator\ValidationIncident;

/**
 * Passes validation, then rejects the submission on a business rule of its own: it reports the
 * error on its validation manager and returns its Error view, which shows the error to the client.
 * Dispatched through the "form-population-test" context (Config/factories.xml).
 */
#[Route('/action-errors/report', name: 'action_errors.report', methods: ['POST'], outputType: 'html')]
class ReportAction extends Action
{
    #[\Override]
    public function isSimple(): bool
    {
        return false;
    }

    public function executeWrite(WebRequest $rd): string
    {
        $validationManager = $this->getInitContext()?->getValidationManager();
        if ($validationManager === null) {
            throw new \RuntimeException('ReportAction requires a validation manager.');
        }

        $incident = new ValidationIncident(null);
        $incident->addError(new ValidationError('Buyer contact is missing.', 'buyer_contact_id_missing', []));
        $validationManager->addIncident($incident);

        return 'Error';
    }

    public function registerWriteValidators(): void
    {
        $initContext = $this->getInitContext();
        $context = $this->getContext();
        if ($initContext === null || $context === null) {
            throw new \RuntimeException('ReportAction requires an initialized Action context.');
        }
        $validationManager = $initContext->getValidationManager();
        if (!$validationManager instanceof IValidatorContainer) {
            throw new \RuntimeException('ReportAction requires an IValidatorContainer validation manager.');
        }

        ValidatorBuilder::on($validationManager, $context)->string('Name', required: true);
    }
}
