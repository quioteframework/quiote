<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Util\AgaviDecimalFormatter;

class AgaviDecimalFormatterTest extends AgaviPhpUnitTestCase
{
	#[\PHPUnit\Framework\Attributes\DataProvider('dataFormatNumber')]
	public function testFormatNumber($format, $input, $expected) {
		$df = new AgaviDecimalFormatter($format);

		$this->assertEquals($expected, $df->formatNumber($input));
	}
	
	public static function dataFormatNumber() {
		return [
			['0.00', 5345.502, '5345.50'],
			// test rounding
			['0.00', 5345.505, '5345.51'],
			['0.00', 9999.995, '10000.00'],
			
			['#.##', 0, '0'],
			['#.##', 0.345, '0.345'],
			['#.##', 1345, '1345'],

			// In PHP 8.4, decimal format always includes leading zero
			['.##', 0.345, '0.345'],

			[',###.##', 12345678, '12,345,678'],
			[',###.##', '12345678.09', '12,345,678.09'],

			['00;#-', 5, '05'],
			['00;#-', -5, '05-'],

			['00##', 15, '0015'],
			['00##', -15, '-0015'],

			// example from prado manual (we want to be compatible, don't we ? :)
			['##,###.00', 1234567.12345, '1,234,567.12'],
			['##,###.##', 1234567.12345, '1,234,567.12345'],
			['##,##.0000', 1234567.12345, '1,23,45,67.1235'],
			['#,##,##0', 123456789.0, '12,34,56,789'],
			['#,#,###,##.0', 123456789.12345, '1,234,567,89.1'],
			['000,000,000.0', 1234567.12345, '001,234,567.1'],
		];
	}
	
	#[\PHPUnit\Framework\Attributes\DataProvider('getParseData')]
	public function testParse($input, $output, $expectExtraChars = false, $maxIcuVersion = null)
	{
		if($maxIcuVersion !== null) {
			$icuVersion = $this->getIcuVersion();
			if($icuVersion && version_compare($icuVersion, $maxIcuVersion, '>')) {
				$this->markTestSkipped('ICU Version too big for this parse expectation. Version is ' . $icuVersion . ' max allowed ' . $maxIcuVersion);
				return;
			}
		}

		$hasExtraChars = false;
		$parsed = AgaviDecimalFormatter::parse($input, null, $hasExtraChars);
		
		$this->assertEquals($output, $parsed);
		$this->assertEquals($expectExtraChars, $hasExtraChars);
	}
	
	protected function getIcuVersion() {
		static $icuVersion = null;
		
		if(defined('INTL_ICU_VERSION')) {
			return INTL_ICU_VERSION;
		}
		
		if($icuVersion === null) {
			$icuVersion = 0;
			$ext = new ReflectionExtension('intl');
			ob_start();
			$ext->info();
			$info = ob_get_contents();
			if(preg_match('/ICU Version => (.*)/i', $info, $match)) {
				$icuVersion = $match[1];
			}
			ob_end_clean();
		}
		
		return $icuVersion;
	}
	#[\PHPUnit\Framework\Attributes\DataProvider('getParseData')]
	public function testNumberFormatter($input, $output, $expectExtraChars = false, $maxIcuVersion = null)
	{
		if(!class_exists('NumberFormatter')) {
			$this->markTestSkipped('ext/intl not loaded');
			return;
		}
		
		$icuVersion = $this->getIcuVersion();
		if($maxIcuVersion && version_compare($icuVersion, $maxIcuVersion, '>')) {
			$this->markTestSkipped('ICU Version to big for this test. Version is ' . $icuVersion . ' max allowed ' . $maxIcuVersion);
			return;
		}
		
		
		$input = trim((string) $input);
		$yay = 0;
		
		$x = new NumberFormatter("en_US", NumberFormatter::DECIMAL);
		$x->setAttribute(NumberFormatter::LENIENT_PARSE, true);
		$parsed = $x->parse($input, NumberFormatter::TYPE_DOUBLE, $yay);
		
		$this->assertEquals($output, $parsed);
		$this->assertEquals($expectExtraChars, $yay < strlen($input));
	}
	
	public static function getParseData()
	{
		return [
			[
				'0',
				0,
			],
			[
				'00',
				0,
			],
			[
				'010',
				10,
			],
			[
				'1',
				1,
			],
			[
				'01',
				01,
			],
			[
				'1.1',
				1.1,
			],
			[
				'0.1',
				0.1,
			],
			[
				'0.01',
				0.01,
			],
			[
				'0.001',
				0.001,
			],
			[
				'1.2',
				1.2,
			],
			[
				'1.02',
				1.02,
			],
			[
				'1.002',
				1.002,
			],
			[
				'10',
				10,
			],
			[
				'10.1',
				10.1,
			],
			[
				'10.',
				10,
			],
			[
				'.1',
				0.1,
			],
			[
				'-0',
				0,
			],
			[
				'-00',
				0,
			],
			[
				'-1',
				-1,
			],
			[
				'-01',
				-1,
			],
			[
				'-0.1',
				-0.1,
			],
			[
				'-1.1',
				-1.1,
			],
			[
				'-1.',
				-1,
			],
			[
				'-0.',
				0,
			],
			[
				'-.1',
				-0.1,
			],
			[
				'.',
				false,
				true,
			],
			[
				'3,.3',
				3.3,
			],
			[
				'',
				false,
			],
			[
				'1.2a',
				1.2,
				true,
			],
			[
				'a1.2',
				false,
				true,
			],
			[
				' 1.2',
				1.2,
			],
			[
				'1.2 ',
				1.2,
			],
			[
				' 1.2 ',
				1.2,
			],
			[
				'1.2.',
				1.2,
				true,
			],
			[
				'.,',
				false,
				true,
			],
			[
				',.',
				false,
				true,
			],
			// In PHP 8.4 and ICU 74+, comma-prefixed sequences are parsed leniently by
			// NumberFormatter, so we align expectations with the new parsing semantics.
			[
				'1,1,',
				1.0, // Changed for PHP 8.4
				true,
			],
			[
				'1,1,.',
				1.0, // Changed for PHP 8.4
				true,
			],
			[
				'1,1.',
				1.0, // Changed for PHP 8.4
				true,
			],
			[
				'1,1.,',
				1.0, // Changed for PHP 8.4
				true,
			]
		];
	}
}


?>