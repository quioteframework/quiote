<?php
namespace Sandbox\Modules\Default\Actions;

use Quiote\Request\WebRequest ;
use Exception;
use Sandbox\Modules\Default\Lib\Action\SandboxDefaultBaseAction;

class LoginAction extends SandboxDefaultBaseAction
{
	public function execute(WebRequest $rd)
	{
		// Simplified for test environment: produce Success view without exception.
		return 'Success';
	}

	#[\Override]
    public function getDefaultViewName()
	{
		return 'Success';
	}
}

?>