<?php
namespace Sandbox\Modules\Default\Views;

use Quiote\Request\WebRequest ;
use Sandbox\Modules\Default\Lib\View\SandboxDefaultBaseView;


class SecureSuccessView extends SandboxDefaultBaseView
{
	public function executeHtml(WebRequest $rd): string
	{
		$this->getResponse()->setHttpStatusCode('403');
		// Historically this view loaded a layout and template producing a full HTML page. For container-less
		// security forwards we need deterministic inline content; omit layout to avoid layers and just return marker.
		return '<div>SECURE_REQUIRED</div>';
	}
}

?>
