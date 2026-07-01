<?php
class Default_Error404SuccessView extends SampleAppDefaultBaseView
{

	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);

		// set the title
		$this->setAttribute('_title', $this->tm->_('404 Not Found', 'default.ErrorActions'));

		$this->container->getResponse()->setHttpStatusCode('404');
	}

	public function executeXmlrpc(RequestDataHolder $rd)
	{
		return [
			'faultCode' => -32601, // as per http://xmlrpc-epi.sourceforge.net/specs/rfc.fault_codes.php
			'faultString' => 'requested method not found',
		];
	}
	
	public function executeText(RequestDataHolder $rd)
	{
		return
			'Usage: console.php <command> [OPTION]...' . PHP_EOL .
			PHP_EOL .
			'Commands:' . PHP_EOL .
			'  viewproduct <id>' . PHP_EOL .
			'    Retrieves product details given a product ID.' . PHP_EOL .
			'  listproducts' . PHP_EOL .
			'    Lists all products in the application.' . PHP_EOL;
	}
}

?>