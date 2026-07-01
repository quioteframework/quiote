<?php
class Default_Widgets_HeaderSuccessView extends SampleAppDefaultBaseView
{

	public function executeHtml(RequestDataHolder $rd)
	{
		// will automatically load "slot" layout for us
		$this->setupHtml($rd);
	}

}

?>