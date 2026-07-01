<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Sandbox\Modules\ControllerTests\Lib\Action\SandboxControllerTestsBaseAction;

class CredentialsAction extends SandboxControllerTestsBaseAction
{
    #[\Override]
    public function isSecure() { return true; }
    public function getCredentials() { return 'admin'; }
    #[\Override]
    public function getDefaultViewName() { return 'Success'; }
}
