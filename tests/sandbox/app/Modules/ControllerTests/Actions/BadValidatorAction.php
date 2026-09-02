<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Sandbox\Modules\ControllerTests\Lib\Action\SandboxControllerTestsBaseAction;

/**
 * Deliberately paired with a Validate/BadValidator.xml carrying an unknown
 * validator parameter, to exercise ActionTestCase::performValidation()'s
 * handling of a ConfigurationException raised at validator-compile time.
 */
class BadValidatorAction extends SandboxControllerTestsBaseAction
{
    #[\Override]
    public function isSimple()
    {
        return true;
    }

    #[\Override]
    public function getDefaultViewName()
    {
        return 'Success';
    }
}
