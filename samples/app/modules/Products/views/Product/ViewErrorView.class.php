<?php

class Products_Product_ViewErrorView extends SampleAppProductsBaseView
{
	/**
	 * Execute any presentation logic and set template attributes.	 */
	public function executeHtml(RequestDataHolder $rd)
	{
		return $this->createForwardContainer(Config::get('actions.error_404_module'), Config::get('actions.error_404_action'));
	}

	/**
	 * Execute any presentation logic for JSON requests.
	 */
	public function executeJson(RequestDataHolder $rd)
	{
		return json_encode(
			[
				'_error' => 404,
			]
		);
	}
	
	public function executeText(RequestDataHolder $rd)
	{
		$this->getResponse()->setExitCode(1);
		
		return 'No such product';
	}
}

?>