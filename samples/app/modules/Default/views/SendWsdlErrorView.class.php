<?php

class Default_SendWsdlErrorView extends SampleAppDefaultBaseView
{
	public function executeWsdl(RequestDataHolder $rd)
	{
		$this->getResponse()->setHttpStatusCode(404);
	}
}

?>