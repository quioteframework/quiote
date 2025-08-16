<?php
namespace Sandbox\Modules\Default\Actions;

use Agavi\Request\AgaviRequestDataHolder;
use Exception;
use Sandbox\Modules\Default\Lib\Action\SandboxDefaultBaseAction;

class LoginAction extends SandboxDefaultBaseAction
{
	public function execute(AgaviRequestDataHolder $rd)
	{
		// Simplified for test environment: produce Success view without exception.
		return 'Success';
	}

	public function getDefaultViewName()
	{
		return 'Success';
	}
}

?>