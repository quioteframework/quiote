<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Testing\Attributes\Bootstrap;
use Quiote\Config\Config;

require_once(__DIR__ . '/../../../../Quiote/Config/Config.php');

/**
 * Test class for Config with bootstrap disabled
 */
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[Bootstrap(false)]
class ConfigTest extends PhpUnitTestCase
{
	#[\Override]
    public function setUp(): void
	{
		Config::clear();
	}

	public function testInitiallyEmpty()
	{
		$expected = [];
		// core.quiote_dir is set as readonly when Quiote.php is loaded
		if (Config::has('core.quiote_dir')) {
			$expected['core.quiote_dir'] = Config::get('core.quiote_dir');
		}
		$this->assertEquals($expected, Config::toArray());
		$this->assertNull(Config::get('something'));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('providerGetSet')]
	public function testGetSet($key, $value)
	{
		$this->assertTrue(Config::set($key, $value));
		$this->assertEquals($value, Config::get($key));
		$this->assertTrue(Config::has($key));
		$this->assertFalse(Config::isReadonly($key));
		$this->assertTrue(Config::remove($key));
	}
	public static function providerGetSet()
	{
		return [
			'string key'                => ['foobar', 'baz'],
			'string key with period'    => ['some.thing', 'ohai'],
			// 'string key with null byte' => array("f\0oo", 'nullbyte'), // can't do this because PHPUnit doesn't do var_export(serialize(...)), so the null byte fucks everything up
			'integer key'               => [123, 'qwe'],
			'octal number key'          => [0123, 'yay'],
		];
	}

	public function testHas()
	{
		Config::set('fubar', '123qwe');
		$this->assertTrue(Config::has('fubar'));
	}

	public function testClear()
	{
		Config::clear();
		$expected = [];
		// core.quiote_dir is set as readonly when Quiote.php is loaded and survives clear()
		if (Config::has('core.quiote_dir')) {
			$expected['core.quiote_dir'] = Config::get('core.quiote_dir');
		}
		$this->assertEquals($expected, Config::toArray());
	}

	public function testRemove()
	{
		Config::set('opa', 'yay');
		$this->assertTrue(Config::remove('opa'));
		$this->assertFalse(Config::remove('blu'));
		$this->assertFalse(Config::has('opa'));
		$this->assertFalse(Config::has('blu'));
	}

	public function testFromArray()
	{
		$data = ['foo' => 'bar', 'bar' => 'baz'];
		Config::clear();
		Config::fromArray($data);
		$expected = $data;
		// core.quiote_dir is set as readonly when Quiote.php is loaded
		if (Config::has('core.quiote_dir')) {
			$expected['core.quiote_dir'] = Config::get('core.quiote_dir');
		}
		$this->assertEquals($expected, Config::toArray());
	}

	public function testFromArrayMerges()
	{
		$data = ['foo' => 'bar', 'bar' => 'baz'];
		Config::clear();
		Config::set('baz', 'lol');
		Config::fromArray($data);
		$expected = ['baz' => 'lol'] + $data;
		// core.quiote_dir is set as readonly when Quiote.php is loaded
		if (Config::has('core.quiote_dir')) {
			$expected['core.quiote_dir'] = Config::get('core.quiote_dir');
		}
		$this->assertEquals($expected, Config::toArray());
	}

	public function testFromArrayMergesAndOverwrites()
	{
		$data = ['foo' => 'bar', 'bar' => 'baz', 'baz' => 'qux'];
		Config::clear();
		Config::set('baz', 'lol');
		Config::fromArray($data);
		$expected = ['baz' => 'qux'] + $data;
		// core.quiote_dir is set as readonly when Quiote.php is loaded
		if (Config::has('core.quiote_dir')) {
			$expected['core.quiote_dir'] = Config::get('core.quiote_dir');
		}
		$this->assertEquals($expected, Config::toArray());
	}

	public function testFromArrayMergesAndReindexes()
	{
		$data = ['zomg', 'lol'];
		Config::clear();
		Config::set(2, 'yay');
		Config::set(1, 'aha');
		Config::set(0, 'omg', true, true);
		Config::fromArray($data);
		$expected = [2 => 'yay', 0 => 'omg', 1 => 'lol'];
		// core.quiote_dir is set as readonly when Quiote.php is loaded
		if (Config::has('core.quiote_dir')) {
			$expected['core.quiote_dir'] = Config::get('core.quiote_dir');
		}
		$this->assertEquals($expected, Config::toArray());
	}

	public function testHasNullValue()
	{
		Config::set('fubar', null);
		$this->assertTrue(Config::has('fubar'));
		$this->assertFalse(Config::has('fubaz'));
	}

	public function testGetDefault()
	{
		Config::set('some.where', 'ohai');
		$this->assertEquals('ohai', Config::get('some.where'));
		$this->assertEquals('ohai', Config::get('some.where', 'bai'));
		$this->assertEquals('bai', Config::get('not.there', 'bai'));
	}

	public function testSetOverwrite()
	{
		Config::set('foo.bar', '123');
		$this->assertEquals('123', Config::get('foo.bar'));
		$this->assertFalse(Config::set('foo.bar', '456', false));
		$this->assertEquals('123', Config::get('foo.bar'));
		$this->assertTrue(Config::set('foo.bar', '456', true));
		$this->assertEquals('456', Config::get('foo.bar'));
		$this->assertTrue(Config::set('foo.bar', '789'));
		$this->assertEquals('789', Config::get('foo.bar'));
	}

	public function testSetReadonly()
	{
		Config::set('bulletproof', 'abc', true, true);
		$this->assertEquals('abc', Config::get('bulletproof'));
		$this->assertFalse(Config::set('bulletproof', '123'));
		$this->assertEquals('abc', Config::get('bulletproof'));
		$this->assertFalse(Config::set('bulletproof', '123', true));
		$this->assertEquals('abc', Config::get('bulletproof'));
		$this->assertFalse(Config::set('bulletproof', '123', true, true));
		$this->assertEquals('abc', Config::get('bulletproof'));
	}

	public function testIsReadonly()
	{
		Config::set('WORM', 'yay', true, true);
		Config::set('WMRM', 'yay');
		$this->assertTrue(Config::isReadonly('WORM'));
		$this->assertFalse(Config::isReadonly('WMRM'));
	}

	public function testReadonlySurvivesClear()
	{
		Config::set('WORM', 'yay', true, true);
		Config::set('WMRM', 'yay');
		Config::clear();
		$this->assertTrue(Config::has('WORM'));
		$this->assertFalse(Config::has('WMRM'));
	}

	public function testFromArrayMergesButDoesNotOverwriteReadonlies()
	{
		$data = ['foo' => 'bar', 'bar' => 'baz', 'baz' => 'qux'];
		Config::clear();
		Config::set('baz', 'lol', true, true);
		Config::fromArray($data);
		$expected = ['baz' => 'lol'] + $data;
		// core.quiote_dir is set as readonly when Quiote.php is loaded
		if (Config::has('core.quiote_dir')) {
			$expected['core.quiote_dir'] = Config::get('core.quiote_dir');
		}
		$this->assertEquals($expected, Config::toArray());
	}

	public function testReadonlySurvivesRemove()
	{
		Config::set('bla', 'goo', true, true);
		$this->assertFalse(Config::remove('bla'));
		$this->assertTrue(Config::has('bla'));
	}

	public function testGetSetStringInteger() {
		Config::set('10', 'ten');
		$this->assertEquals('ten', Config::get(10));
		Config::set(21, 'twentyone');
		$this->assertEquals('twentyone', Config::get('21'));
	}

}