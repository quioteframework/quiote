<?php
class Default_SecureSuccessView extends SampleAppDefaultBaseView
{
	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		// set the title
		$this->setAttribute('_title', $this->tm->_('Permission Denied', 'default.ErrorActions'));
		
		$this->getResponse()->setHttpStatusCode('403');
	}

}

?>