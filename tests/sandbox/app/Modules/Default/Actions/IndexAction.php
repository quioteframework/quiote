<?php
namespace Sandbox\Modules\Default\Actions;

use Sandbox\Modules\Default\Lib\Action\SandboxDefaultBaseAction;

class IndexAction extends SandboxDefaultBaseAction
{
	public function executeRead(\Quiote\Request\WebRequest $rd): string
	{
		return 'Success';
	}
	/**
	 * Returns the default view if the action does not serve the request
	 * method used.
	 * @return     string A string containing the view name associated with this action.
	 */
	#[\Override]
    public function getDefaultViewName(): string
	{
		return 'Success';
	}
}

?>