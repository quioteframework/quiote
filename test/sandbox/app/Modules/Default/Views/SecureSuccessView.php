<?php
namespace Sandbox\Modules\Default\Views;

use Agavi\Request\AgaviRequestDataHolder;
use Sandbox\Modules\Default\Lib\View\SandboxDefaultBaseView;


class SecureSuccessView extends SandboxDefaultBaseView
{
	public function executeHtml(AgaviRequestDataHolder $rd)
	{
		$this->getResponse()->setHttpStatusCode('403');
		// Historically this view loaded a layout and template producing a full HTML page. For container-less
		// security forwards we need deterministic inline content; omit layout to avoid layers and just return marker.
		return '<div>SECURE_REQUIRED</div>';
	}
}

?>
