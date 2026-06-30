<?php

use Agavi\Testing\AgaviActionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @AgaviActionName Products.View
 * @AgaviModuleName Products
 */
class Products_Product_ViewActionTest extends AgaviActionTestCase
{
	protected static $products = [
		[
			'id'    => 8172401,
			'name'  => 'TPS Report Cover Sheet',
			'price' => 0.89,
		],
		[
			'id'    => 917246,
			'name'  => 'Weighted Companion Cube',
			'price' => 129.99,
		],
		[
			'id'    => 7856122,
			'name'  => 'Longcat',
			'price' => 14599,
		],
		[
			'id'    => 123456,
			'name'  => 'Red Stapler',
			'price' => 3.14,
		],
		[
			'id'    => 3165463,
			'name'  => 'Sildenafil Citrate',
			'price' => 14.69,
		],
	];
	
	public function __construct($name = NULL, array $data = [], $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		$this->actionName = 'Product.View';
		$this->moduleName = 'Products';
	}
	
	#[DataProvider('successViewValidProductsData')]
	public function testSuccessViewValidProducts($parameters, $price)
	{
		$this->setRequestMethod('read');
		$this->setRequestData($parameters); // no-op retained for BC
		$this->runAction();
		$this->assertValidatedArgument('id');
		$this->assertViewNameEquals('Success');
		$this->assertViewModuleNameEquals('Products');
		$this->assertContainerAttributeExists('product');
		$this->assertEquals($price, $this->getAttribute('product')->getPrice());
	}
	
	public static function successViewValidProductsData()
	{
		$retval = [];
		foreach(self::$products as $product) {
			$retval['id only: ' . $product['id']] = [['id' => $product['id']], $product['price']];
		}
		foreach(self::$products as $product) {
			$retval['id+name: ' . $product['id'] . '/' . $product['name']] = [['id' => $product['id'], 'name' => $product['name']], $product['price']];
		}
		return $retval;
	}
	
	#[DataProvider('errorViewInvalidProductsData')]
	public function testErrorViewInvalidProducts($parameters)
	{
		$this->setRequestMethod('read');
		$this->setArguments($parameters);
		$this->runAction();
		$this->assertValidatedArgument('id');
		$this->assertViewNameEquals('Error');
		$this->assertViewModuleNameEquals('Products');
	}
	
	public function testErrorViewFailedProductValidation()
	{
		$this->setRequestMethod('read');
		$this->setArguments(['id' => '']);
		$this->runAction();
		$this->assertValidatedArgument('id');
		$this->assertFailedArgument('id');
		$this->assertViewNameEquals('Error');
		$this->assertViewModuleNameEquals('Products');
	}
	
	public static function errorViewInvalidProductsData()
	{
		return [
			'only product name given' => [['name' => 'Red Stapler']],
			'invalid product id given' => [['id' => 81236123]],
			'negative product id given' => [['id' => -1]],
			'id and name given, id invalid' => [['id' => 123457, 'name' => 'Red Stapler']],
			'id and name given, name invalid' => [['id' => 123456, 'name' => 'Red StaplerZOMG']],
			'id and name given, both invalid' => [['id' => -1, 'name' => 'Red StaplerZOMG']],
		];
	}
	
	public function testIsNotSimple()
	{
		$this->assertIsNotSimple();
	}
	
	public function testDefaultView()
	{
		$this->assertDefaultView('Input');
	}
	
	public function testReadMethod()
	{
		$this->assertHandlesMethod('read');
	}
	
	public function testWriteMethod()
	{
		$this->assertNotHandlesMethod('write');
	}
}

?>