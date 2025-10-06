<?php
namespace Sandbox\Modules\Default\Views;

use Agavi\Request\AgaviWebRequest ;
use Sandbox\Modules\Default\Lib\View\SandboxDefaultBaseView;

class IndexSuccessView extends SandboxDefaultBaseView
{
	public function executeHtml(AgaviWebRequest $rd)
	{
		$this->setupHtml($rd);

		$this->setAttribute('title', 'Index');
	}
}

?>