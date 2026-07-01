<?php
namespace Sandbox\Modules\Default\Views;

use Quiote\Request\WebRequest ;
use Sandbox\Modules\Default\Lib\View\SandboxDefaultBaseView;

class LoginSuccessView extends SandboxDefaultBaseView
{
	public function executeHtml(WebRequest $rd)
	{
		// Skip layout for container-less system forward path: inline marker content only.
		$this->setAttribute('title', 'Login');
		return '<div>LOGIN_REQUIRED</div>';
	}
}

?>