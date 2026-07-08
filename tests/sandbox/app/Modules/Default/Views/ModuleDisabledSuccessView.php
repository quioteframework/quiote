<?php
namespace Sandbox\Modules\Default\Views;

use Quiote\Request\WebRequest ;
use Sandbox\Modules\Default\Lib\View\SandboxDefaultBaseView;


class ModuleDisabledSuccessView extends SandboxDefaultBaseView
{
	public function executeHtml(WebRequest $rd): void
	{
		$this->setupHtml($rd);
		
		$this->getResponse()->setHttpStatusCode('503');
	}
}

?>
