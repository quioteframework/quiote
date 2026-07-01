<?php
class Default_LoginErrorView extends SampleAppDefaultBaseView
{
	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);
		
		// set the title
		$this->setAttribute('_title', $this->tm->_('Login', 'default.Login'));
		
		// set error messages from the user login method
		if(($error = $this->getAttribute('error')) !== null) {
			$this->container->getValidationManager()->setError($error, $this->context->getTranslationManager()->_('Wrong ' . ucfirst($error), 'default.errors.Login'));
		}
		
		// use the input template, default would be LoginError, but that doesn't exist
		$this->getLayer('content')->setTemplate('LoginInput');
	}
}

?>