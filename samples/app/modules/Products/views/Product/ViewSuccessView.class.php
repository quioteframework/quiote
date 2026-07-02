<?php

class Products_Product_ViewSuccessView extends SampleAppProductsBaseView
{

	/**
	 * Execute any presentation logic and set template attributes.	 */
	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		// set the title
		$this->setAttribute('_title', $this->tm->_('Congratulations!', 'default.SearchEngineSpam'));
	}

	public function executeText(RequestDataHolder $rd)
	{
		$product = $this->getAttribute('product');
		
		return
			'ID:    ' . $product->getId() . PHP_EOL .
			'Name:  ' . $product->getName() . PHP_EOL .
			'Price: ' . sprintf('%01.2f', $product->getPrice());
	}
}

?>