<?php

require_once(__DIR__ . '/../Lib/View/SandboxControllerTestsBaseView.php');

use Agavi\Request\AgaviRequestDataHolder;

class ControllerTests_SimpleActionSuccessView extends SandboxControllerTestsBaseView
{
	public function executeHtml(AgaviRequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		$this->setAttribute('_title', 'SimpleAction');
	}
}

?>