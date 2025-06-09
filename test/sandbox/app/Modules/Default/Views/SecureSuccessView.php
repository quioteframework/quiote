<?php
namespace Sandbox\Modules\Default\Views;

use Agavi\Request\AgaviRequestDataHolder;
use Sandbox\Modules\Default\Lib\View\SandboxDefaultBaseView;


class SecureSuccessView extends SandboxDefaultBaseView
{
	public function executeHtml(AgaviRequestDataHolder $rd)
	{
		$this->setupHtml($rd);
		
		$this->getResponse()->setHttpStatusCode('403');
	}
}

?>
