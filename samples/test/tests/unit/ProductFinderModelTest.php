<?php

use Quiote\Testing\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ProductFinderModelTest extends UnitTestCase
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
	
	#[DataProvider('productNamePrices')]
	public function testValidProductPricesByName($productName, $price)
	{
		$finder = $this->getContext()->getModel('ProductFinder');
		$this->assertEquals($price, $finder->retrieveByName($productName)->getPrice());
	}
	
	public static function productNamePrices()
	{
		$retval = [];
		foreach(self::$products as $product) {
			$retval[$product['name']] = [
				$product['name'],
				$product['price'],
			];
		}
		return $retval;
	}
	
	#[DataProvider('productIdPrices')]
	public function testValidProductPricesById($productId, $price)
	{
		$finder = $this->getContext()->getModel('ProductFinder');
		$this->assertEquals($price, $finder->retrieveById($productId)->getPrice());
	}
	
	public static function productIdPrices()
	{
		$retval = [];
		foreach(self::$products as $product) {
			$retval[$product['name']] = [
				$product['id'],
				$product['price'],
			];
		}
		return $retval;
	}
	
	#[DataProvider('productInfoPrices')]
	public function testValidProductPricesByInfo($productId, $productName, $price)
	{
		$finder = $this->getContext()->getModel('ProductFinder');
		$this->assertEquals($price, $finder->retrieveByIdAndName($productId, $productName)->getPrice());
	}
	
	public static function productInfoPrices()
	{
		$retval = [];
		foreach(self::$products as $product) {
			$retval[$product['name']] = [
				$product['id'],
				$product['name'],
				$product['price'],
			];
		}
		return $retval;
	}
	
	public function testNullForUnknownProductName()
	{
		$this->assertNull($this->getContext()->getModel('ProductFinder')->retrieveByName('unknown product'));
	}
	
	public function testNullForUnknownProductId()
	{
		$this->assertNull($this->getContext()->getModel('ProductFinder')->retrieveById(-1));
	}
	
	public function testNullForUnknownProductInfo()
	{
		$this->assertNull($this->getContext()->getModel('ProductFinder')->retrieveByIdAndName(-1, 'unknown product'));
	}
	
	public function testNullForPartiallyValidProductInfo()
	{
		$this->assertNull($this->getContext()->getModel('ProductFinder')->retrieveByIdAndName(123456, 'Red StaplerZOMG'));
		$this->assertNull($this->getContext()->getModel('ProductFinder')->retrieveByIdAndName(1234567, 'Red Stapler'));
	}
}

?>