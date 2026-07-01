<?php

use Quiote\Testing\ViewTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class Products_Product_ViewSuccessViewTest extends ViewTestCase
{

	public function __construct($name = NULL, array $data = [], $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		// FIXME: the underlying issue must be solved
		$this->actionName = 'Product.View';
		$this->moduleName = 'Products';
		$this->viewName   = 'Success';
	}

	#[DataProvider('supportedOtProvider')]
	public function testHandlesOutputType($ot_name)
	{
		$this->assertHandlesOutputType($ot_name);
	}

	public static function supportedOtProvider()
	{
		return [
			'html'   => ['html'],
			'html'   => ['text'],
			// 'json'   => array('json'),
			'soap'   => ['soap'],
			'xmlrpc' => ['xmlrpc'],
		];
	}

	public function testNotHandlesXmlOutputType()
	{
		$this->assertNotHandlesOutputType('xml');
	}

	// FIXME: needs to be updated
	public function testResponseHtml()
	{		
		$this->setArguments(['product_name' => 'spam']);

		$this->setAttribute('product_id', 1234);
		$this->setAttribute('product_name', 'spam');
		$this->setAttribute('product_price', '123.45');
		$this->runView();
		$this->assertViewResponseHasHTTPStatus(200);
		$this->assertViewResultEquals('');
		$this->assertHasLayer('content');
		$this->assertHasLayer('decorator');
		$this->assertViewRedirectsNot();
		$this->assertContainerAttributeExists('_title');
	}

	// public function testResponseJson()
	// {		
	// Legacy form removed; using direct array above.
	// 
	// 	$this->setAttribute('product_id', 1234);
	// 	$this->setAttribute('product_name', 'spam');
	// 	$this->setAttribute('product_price', '123.45');
	// 	$this->runView('json');
	// 	$this->assertResponseHasHTTPStatus(200);
	// 	$this->assertViewResultEquals('{"product_price":"123.45"}');
	// 	$this->assertResponseHasNoRedirect();
	// }
}

?>