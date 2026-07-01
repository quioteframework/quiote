<?php
class Default_LoginSuccessView extends SampleAppDefaultBaseView
{

	public function executeHtml(RequestDataHolder $rd)
	{
		$res = $this->getResponse();

		// set the autologon cookie if requested
		if($rd->hasParameter('remember')) {
			$res->setCookie('autologon[username]', $rd->getParameter('username'), '+14 days');
			$res->setCookie('autologon[password]', $this->us->getPassword($rd->getParameter('username')), '+14 days');
		}

		if($this->us->hasAttribute('redirect', 'org.quiote.SampleApp.login')) {
			$this->getResponse()->setRedirect($this->us->removeAttribute('redirect', 'org.quiote.SampleApp.login'));
			return;
		}

		$this->setupHtml($rd);

		// set the title
		$this->setAttribute('_title', $this->tm->_('Login Successful', 'default.Login'));
	}

}

?>