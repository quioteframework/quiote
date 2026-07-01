<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Sandbox\Modules\ControllerTests\Lib\Action\SandboxControllerTestsBaseAction;

class SecureActionAction extends SandboxControllerTestsBaseAction
{
    #[\Override]
    public function isSecure() { return true; }
    #[\Override]
    public function getDefaultViewName() { return 'Success'; }
}
