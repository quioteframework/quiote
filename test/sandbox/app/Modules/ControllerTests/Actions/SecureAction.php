<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Sandbox\Modules\ControllerTests\Lib\Action\SandboxControllerTestsBaseAction;

class SecureAction extends SandboxControllerTestsBaseAction
{
    public function isSecure() { return true; }
    public function getDefaultViewName() { return 'Success'; }
}
