<?php
class Default_LoginInputView extends SampleAppDefaultBaseView
{
	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);
		
		// set the title
		$this->setAttribute('_title', $this->tm->_('Login', 'default.Login'));
		
		// our login form is displayed. so let's remove that cookie thing there
		$this->getResponse()->setCookie('autologon[username]', false);
		$this->getResponse()->setCookie('autologon[password]', false);
		
		if($this->getContainer()->hasAttributeNamespace('org.quiote.controller.forwards.login')) {
			// we were redirected to the login form by the controller because the requested action required security
			// so store the input URL in the session for a redirect after login
			$this->us->setAttribute('redirect', $this->rq->getUrl(), 'org.quiote.SampleApp.login');
		} else {
			// clear the redirect URL just to be sure
			// but only if request method is "read", i.e. if the login form is served via GET!
			$this->us->removeAttribute('redirect', 'org.quiote.SampleApp.login');
		}
	}
}

?>