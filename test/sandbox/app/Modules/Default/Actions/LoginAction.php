<?php
namespace Sandbox\Modules\Default\Actions;

use Agavi\Request\AgaviRequestDataHolder;
use Exception;
use Sandbox\Modules\Default\Lib\Action\SandboxDefaultBaseAction;

class LoginAction extends SandboxDefaultBaseAction
{
	public function execute(AgaviRequestDataHolder $rd)
	{
		// remove this execute() method and create executeRead() and executeWrite() methods or equivalents
		throw new Exception('Default_LoginAction is not yet implemented. ' .
			'This is only a stub that serves as a reminder for you to do this.');
	}

	public function getDefaultViewName()
	{
		return 'Success';
	}
}

?>