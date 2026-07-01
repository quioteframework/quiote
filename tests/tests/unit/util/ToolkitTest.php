<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\Toolkit;
use Quiote\Config\Config;
use Quiote\Exception\QuioteException;

class ToolkitTest extends PhpUnitTestCase
{

	public function testNormalizePath()
	{
		$this->assertEquals('path', Toolkit::normalizePath("path"));
		$this->assertEquals('/path/warm/hot/unbearable', Toolkit::normalizePath('/path/warm/hot/unbearable'));
		$this->assertEquals('/path/warm/hot/unbearable', Toolkit::normalizePath('\path\warm\hot\unbearable'));
		$this->assertEquals('/path/warm/hot//unbearable', Toolkit::normalizePath('\path\\warm\hot\\\\unbearable'));
	}

	public function testMkdir()
	{
		$this->assertTrue(Toolkit::mkdir('_testing_path'));
		rmdir('_testing_path');
	}

	public function testStringBase()
	{
		$amount = 0;
		$this->assertEquals("string", Toolkit::stringBase("stringbase", "stringother"));
		$this->assertEquals("string", Toolkit::stringBase("stringbase", "stringother", $amount));
		$this->assertEquals(6, $amount);
		$this->assertEquals("hu", Toolkit::stringBase("hurray", "hungry"));
		$this->assertEquals(NULL, Toolkit::stringBase("astringbase", "stringother"));
	}

	public function testExpandVariables()
	{
		$string = "{bbq}";
		$arguments = ['hehe' => 'hihi', '{bbq}' => 'soon'];
		$this->assertEquals('{bbq}', Toolkit::expandVariables($string));
		$this->assertEquals('${foo}', Toolkit::expandVariables('$foo'));
		$this->assertEquals('${foo}', Toolkit::expandVariables('{$foo}'));
	}

	public function testExpandDirectives()
	{
		Config::set('whatever', 'something');
		$value = "whatever %directive% asdasdasd %whatever% ";
		$result = "whatever %directive% asdasdasd something ";
		$this->assertEquals($result, Toolkit::expandDirectives($value));
	}

	public function testFloorDivide()
	{
		$rem = 0;
		$this->assertEquals(3, Toolkit::floorDivide(10, 3, $rem));
		$this->assertEquals(1, $rem);
		$this->assertEquals(0, Toolkit::floorDivide(0, 2, $rem));
		$this->assertEquals(0, $rem);
		$this->assertEquals(3, Toolkit::floorDivide(10.5, 3, $rem));
		$this->assertEquals(1, $rem);
	}


	public function testFloorDivideException()
	{
		$this->expectException(QuioteException::class);
		Toolkit::floorDivide(6.9, 3.4, $rem);
	}

	public function testFloorDivideByZero()
	{
		$this->expectException(DivisionByZeroError::class);
		Toolkit::floorDivide(10, 0, $rem);
	}

	public function testIsPortNecessary()
	{
		$this->assertTrue(Toolkit::isPortNecessary('some scheme', 8800));
		$this->assertFalse(Toolkit::isPortNecessary('ftp', 21));
		$this->assertFalse(Toolkit::isPortNecessary('ssh', 22));
		$this->assertFalse(Toolkit::isPortNecessary('https', 443));
		$this->assertFalse(Toolkit::isPortNecessary('nttp', 119));
	}

	public function testGetValueByKeyList()
	{
		$array = ['one' => 'edno', 'two' => 'dve', 'three' => 'tri', 'four' => 'chetiri'];
		$keys = ['one', 'two', 'three'];
		$this->assertEquals('edno', Toolkit::getValueByKeyList($array, $keys));
		$this->assertEquals('dve', Toolkit::getValueByKeyList($array, ['two']));
		$this->assertEquals('dve', Toolkit::getValueByKeyList($array, ['two'], 'default'));
		$this->assertEquals(NULL, Toolkit::getValueByKeyList($array, ['five']));
		$this->assertEquals('pet', Toolkit::getValueByKeyList($array, ['five'], 'pet'));
	}

	public function testIsNotArray()
	{
		$value1 = ['baz' => 'boo'];
		$value2 = ['baz', 'boo'];
		$this->assertTrue(Toolkit::isNotArray("path"));
		$this->assertFalse(Toolkit::isNotArray($value1));
		$this->assertFalse(Toolkit::isNotArray($value2));
	}

	public function testUniqid()
	{
		$id1 = Toolkit::uniqid();
		$id2 = Toolkit::uniqid();
		$id3 = Toolkit::uniqid();
		$this->assertNotEquals($id1, $id2);
		$this->assertNotEquals($id3, $id2);
		$this->assertNotEquals($id1, $id3);
	}

	public function testUniqidWithPrefix()
	{
		$id1 = Toolkit::uniqid('001');
		$id2 = Toolkit::uniqid('001');
		$this->assertNotEquals($id1, $id2);
		$this->assertStringContainsString('001', $id1);
	}

	public function testCanonicalName()
	{
		$this->assertEquals('path', Toolkit::canonicalName("path"));
		$this->assertEquals('/path/warm/hot/unbearable', Toolkit::canonicalName("/path/warm/hot/unbearable"));
		$this->assertEquals('path/warm/hot/unbearable', Toolkit::canonicalName("path.warm.hot.unbearable"));
		$this->assertEquals('/path//warm/hot///unbearable', Toolkit::canonicalName(".path..warm.hot...unbearable"));
	}

	public function testEvaluateModuleDirective()
	{
		Config::set('replace.me', 'replaced value $foo $bar $baz');
		Config::set('modules.foo.bar', 'value $foo %replace.me% %nonexistant%');
		$array = ['foo' => 'replaced_foo', 'bar' => 'replaced_bar'];
		$retval = 'value replaced_foo replaced value replaced_foo replaced_bar ${baz} %nonexistant%';
		$actual = Toolkit::evaluateModuleDirective('foo', 'bar', $array);
		$this->assertEquals($retval, $actual);
	}
	
	#[\PHPUnit\Framework\Attributes\DataProvider('literalizeData')]
	public function testLiteralize($rawValue, $expectedResult, $settings)
	{
		foreach($settings as $key => $value) {
			Config::set($key, $value);
		}
		
		$literalized = Toolkit::literalize($rawValue);
		
		$this->assertEquals($expectedResult, $literalized);
	}
	
	public static function literalizeData()
	{
		return [
			'null' => [null, null, []],
			'empty string' => ['', null, []],
			'array("foo" => "bar")' => [['foo' => 'bar'], ['foo' => 'bar'], []],
			'(string)true' => ['true', true, []],
			'(string)false' => ['false', false, []],
			'(string)yes' => ['yes', true, []],
			'(string)no' => ['no', false, []],
			'(string)on' => ['on', true, []],
			'(string)off' => ['off', false, []],
			'(string)single space' => [' ', null, []],
			'(string)multiple spaces' => ['    ', null, []],
			'(string)newline' => ["\n", null, []],
			'(string)newline and space' => [" \n ", null, []],
			'(string)space true space' => [' true ', true, []],
			'(string)%test.replace%' => ['%test.replace%', 'fooo', ['test.replace' => 'fooo']],
			'(int)5' => [5, 5, []]
		];
	}
	
	#[\PHPUnit\Framework\Attributes\DataProvider('pathData')]
	public function testIsPathAbsolute($path, $expected)
	{
		if($expected) {
			$this->assertTrue(Toolkit::isPathAbsolute($path));
		} else {
			$this->assertFalse(Toolkit::isPathAbsolute($path));
		}
	}
	
	public static function pathData()
	{
		$data = [
			'c:/' => ['c:/', true],
			'c:\\' => ['c:\\', true],
			'c:/Windows' => ['c:/Windows', true],
			'g:/Windows/bar' => ['g:/Windows/bar', true],
			'c:\\windows\\foo' => ['c:\\windows\\foo', true],
			':/foo' => [':/foo', false],
			// UNC paths are absolute too
			'(unc)\\\\some.host' => ['\\\\some.host', true],
			'(unc)\\\\some.host\\foo' => ['\\\\some.host\\foo', true],
			'(unc)\\some.host\\foo' => ['\\some.host\\foo', false],
			
			'/' => ['/', true],
			'/root' => ['/root', true],
			'/FoO/bAR' => ['/FoO/bAR', true],
			'./FoO/bAR' => ['./FoO/bAR', false],
			'../FoO/bAR' => ['../FoO/bAR', false],
			
			// (php does not support backslashes on *nix)
			'\\foo' => ['\\foo', false],
			'\\foo\\bar' => ['\\foo\\bar', false],
			
			'c:' => ['c:', false],
			's/foo/bar' => ['s/foo/bar', false],
			'c:foo' => ['c:foo', false]
		];
		foreach($data as $key => $value) {
			$data['file://' . $key] = ['file://' . $value[0], $value[1]];
		}
		return $data;
	}
	
	#[\PHPUnit\Framework\Attributes\DataProvider('urlData')]
	public function testBuildUrl($parts, $url)
	{
		$this->assertEquals($url, Toolkit::buildUrl($parts));
	}
	
	public static function urlData()
	{
		return [
			[
				['host' => 'example.com'],
				'//example.com/',
			],
			[
				['scheme' => 'http', 'host' => 'example.com'],
				'http://example.com/',
			],
			[
				['scheme' => 'http', 'host' => 'example.com', 'port' => '80'],
				'http://example.com:80/',
			],
			[
				['scheme' => 'http', 'host' => 'example.com', 'user' => 'user', 'pass' => 'pass'],
				'http://user:pass@example.com/',
			],
			[
				['scheme' => 'http', 'host' => 'example.com', 'path' => '/path'],
				'http://example.com/path',
			],
			[
				['scheme' => 'http', 'host' => 'example.com', 'query' => 'param1=foo&param2=bar'],
				'http://example.com/?param1=foo&param2=bar',
			],
			[
				['scheme' => 'http', 'host' => 'example.com', 'fragment' => 'fragment'],
				'http://example.com/#fragment',
			],
			[
				['scheme' => 'http', 'host' => 'example.com', 'port' => '80', 'user' => 'user', 'pass' => 'pass', 'path' => '/path', 'query' => 'param1=foo&param2=bar', 'fragment' => 'fragment'],
				'http://user:pass@example.com:80/path?param1=foo&param2=bar#fragment',
			],
			[
				parse_url('//example.com/'),
				'//example.com/',
			],
			[
				parse_url('http://example.com/?'),
				'http://example.com/',
			],
			[
				parse_url('http://example.com/#'),
				'http://example.com/',
			],
			[
				parse_url('http://user:pass@example.com:80/path?param1=foo&param2=bar#fragment'),
				'http://user:pass@example.com:80/path?param1=foo&param2=bar#fragment',
			],
		];
	}
	
}

?>
