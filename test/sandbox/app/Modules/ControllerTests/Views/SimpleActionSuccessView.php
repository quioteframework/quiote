<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Agavi\Request\AgaviWebRequest ;
use Sandbox\Modules\ControllerTests\Lib\View\SandboxControllerTestsBaseView;

class SimpleActionSuccessView extends SandboxControllerTestsBaseView
{
	public function executeHtml(AgaviWebRequest $rd)
	{
		$this->setupHtml($rd);

		$this->setAttribute('_title', 'SimpleAction');
	}
}

?>