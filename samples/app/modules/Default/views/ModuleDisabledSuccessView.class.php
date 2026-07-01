<?php
class Default_ModuleDisabledSuccessView extends SampleAppDefaultBaseView
{
	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		// set the title
		$this->setAttribute('_title', $this->tm->_('This Module is Disabled', 'default.ErrorActions'));
		
		$this->getResponse()->setHttpStatusCode('503');
	}

}

?>