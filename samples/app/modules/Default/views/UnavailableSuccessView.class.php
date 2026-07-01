<?php
class Default_UnavailableSuccessView extends SampleAppDefaultBaseView
{
	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		// set the title
		$this->setAttribute('_title', $this->tm->_('This Application is Unavailable', 'default.ErrorActions'));
		
		$this->getResponse()->setHttpStatusCode('503');
	}

}

?>