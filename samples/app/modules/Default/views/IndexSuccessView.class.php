<?php
class Default_IndexSuccessView extends SampleAppDefaultBaseView
{

	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		// set the title
		$this->setAttribute('_title', $this->tm->_('Welcome to the Quiote Sample Application', 'default.layout'));
	}

}

?>