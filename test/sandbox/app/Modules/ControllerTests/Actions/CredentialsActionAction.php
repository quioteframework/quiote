<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Sandbox\Modules\ControllerTests\Lib\Action\SandboxControllerTestsBaseAction;

class CredentialsActionAction extends SandboxControllerTestsBaseAction
{
    public function isSecure() { return true; }
    public function getCredentials() { return ['admin']; }
    public function getDefaultViewName() { return 'Success'; }
}
