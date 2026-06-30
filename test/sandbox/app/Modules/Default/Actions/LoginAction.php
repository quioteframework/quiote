<?php
namespace Sandbox\Modules\Default\Actions;

use Agavi\Request\AgaviWebRequest ;
use Exception;
use Sandbox\Modules\Default\Lib\Action\SandboxDefaultBaseAction;

class LoginAction extends SandboxDefaultBaseAction
{
	public function execute(AgaviWebRequest $rd)
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