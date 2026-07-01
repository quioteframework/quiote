<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Quiote\Request\WebRequest ;
use Sandbox\Modules\ControllerTests\Lib\View\SandboxControllerTestsBaseView;

class SimpleActionSuccessView extends SandboxControllerTestsBaseView
{
	public function executeHtml(WebRequest $rd)
	{
		$this->setupHtml($rd);

		$this->setAttribute('_title', 'SimpleAction');
	}
}

?>