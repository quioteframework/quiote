<?php
namespace Sandbox\Modules\Default\Views;

use Agavi\Request\AgaviWebRequest ;
use Sandbox\Modules\Default\Lib\View\SandboxDefaultBaseView;

class LoginSuccessView extends SandboxDefaultBaseView
{
	public function executeHtml(AgaviWebRequest $rd)
	{
		// Skip layout for container-less system forward path: inline marker content only.
		$this->setAttribute('title', 'Login');
		return '<div>LOGIN_REQUIRED</div>';
	}
}

?>