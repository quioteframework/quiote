<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Agavi\Request\AgaviRequestDataHolder;
use Sandbox\Modules\ControllerTests\Lib\View\SandboxControllerTestsBaseView;

class SimpleActionSuccessView extends SandboxControllerTestsBaseView
{
	public function executeHtml(AgaviRequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		$this->setAttribute('_title', 'SimpleAction');
	}
}

?>