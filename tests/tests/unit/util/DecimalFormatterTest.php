<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\DecimalFormatter;

class DecimalFormatterTest extends PhpUnitTestCase
{
	#[\PHPUnit\Framework\Attributes\DataProvider('dataFormatNumber')]
	public function testFormatNumber($format, $input, $expected) {
		$df = new DecimalFormatter($format);

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
	
	public function testGetFormatReturnsNullWhenNoneSet(): void
	{
		$df = new DecimalFormatter();
		$this->assertNull($df->getFormat());
	}

	public function testGetFormatReturnsOriginalFormatString(): void
	{
		$df = new DecimalFormatter('#,##0.00');
		$this->assertEquals('#,##0.00', $df->getFormat());
	}

	public function testSetFormatIsNoOpWhenGivenTheSameFormatTwice(): void
	{
		$df = new DecimalFormatter('0.00');
		$df->setFormat('0.00');
		$this->assertEquals('5.00', $df->formatNumber(5));
	}

	public function testFormatCurrencyInsertsCurrencySymbolAtPlaceholder(): void
	{
		$df = new DecimalFormatter("\u{00A4}#,##0.00");
		$this->assertEquals('$1,234.50', $df->formatCurrency(1234.5, '$'));
	}

	public function testFormatCurrencyWithNegativeNumberUsesNegativeFormat(): void
	{
		$df = new DecimalFormatter("\u{00A4}#,##0.00");
		$this->assertEquals('-$1,234.50', $df->formatCurrency(-1234.5, '$'));
	}

	public function testSetFormatHandlesQuotedLiteralPrefix(): void
	{
		$df = new DecimalFormatter("'USD '0.00");
		$this->assertEquals('USD 5.00', $df->formatNumber(5));
	}

	public function testSetFormatHandlesEmptyQuoteAsLiteralQuoteChar(): void
	{
		$df = new DecimalFormatter("''0.00");
		$this->assertEquals("'5.00", $df->formatNumber(5));
	}

	public function testSetFormatHandlesLiteralPercentPostfix(): void
	{
		$df = new DecimalFormatter('0.00%');
		$this->assertEquals('5.00%', $df->formatNumber(5));
	}

	public function testGetSetRoundingMode(): void
	{
		$df = new DecimalFormatter('0.00');
		$this->assertEquals(DecimalFormatter::ROUND_SCIENTIFIC, $df->getRoundingMode());

		$df->setRoundingMode(DecimalFormatter::ROUND_FINANCIAL);
		$this->assertEquals(DecimalFormatter::ROUND_FINANCIAL, $df->getRoundingMode());
	}

	public function testRoundingModeFinancialRoundsHalfDownAtFive(): void
	{
		$df = new DecimalFormatter('0.0');
		$df->setRoundingMode(DecimalFormatter::ROUND_FINANCIAL);
		$this->assertEquals('1.2', $df->formatNumber(1.25));
		$this->assertEquals('1.3', $df->formatNumber(1.26));
	}

	public function testRoundingModeFloorTruncatesWithoutRoundingUp(): void
	{
		$df = new DecimalFormatter('0.0');
		$df->setRoundingMode(DecimalFormatter::ROUND_FLOOR);
		$this->assertEquals('1.2', $df->formatNumber(1.29));
	}

	public function testRoundingModeCeilAlwaysRoundsUp(): void
	{
		$df = new DecimalFormatter('0.0');
		$df->setRoundingMode(DecimalFormatter::ROUND_CEIL);
		$this->assertEquals('1.3', $df->formatNumber(1.21));
	}

	public function testRoundingModeNoneTruncatesWithoutRounding(): void
	{
		$df = new DecimalFormatter('0.0');
		$df->setRoundingMode(DecimalFormatter::ROUND_NONE);
		$this->assertEquals('1.2', $df->formatNumber(1.29));
	}

	public function testRoundingCarriesThroughNines(): void
	{
		$df = new DecimalFormatter('0.0');
		$df->setRoundingMode(DecimalFormatter::ROUND_CEIL);
		$this->assertEquals('10.0', $df->formatNumber(9.91));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataRoundingModeFromString')]
	public function testGetRoundingModeFromString(string $mode, int $expected): void
	{
		$df = new DecimalFormatter('0.00');
		$this->assertEquals($expected, $df->getRoundingModeFromString($mode));
	}

	/** @return array<int, array{string, int}> */
	public static function dataRoundingModeFromString(): array
	{
		return [
			['none', DecimalFormatter::ROUND_NONE],
			['scientific', DecimalFormatter::ROUND_SCIENTIFIC],
			['financial', DecimalFormatter::ROUND_FINANCIAL],
			['floor', DecimalFormatter::ROUND_FLOOR],
			['ceil', DecimalFormatter::ROUND_CEIL],
		];
	}

	public function testGetRoundingModeFromStringThrowsOnUnknownMode(): void
	{
		$df = new DecimalFormatter('0.00');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown rounding mode "bogus"');
		$df->getRoundingModeFromString('bogus');
	}

	public function testResetRestoresDefaultsAfterFormatWasSet(): void
	{
		$df = new DecimalFormatter('#,##0.00');
		$df->setRoundingMode(DecimalFormatter::ROUND_CEIL);

		$df->reset();

		$this->assertNull($df->getFormat());
		$this->assertEquals(DecimalFormatter::ROUND_SCIENTIFIC, $df->getRoundingMode());
		// After reset, an empty format string means vsprintf gets no directives at all.
		$this->assertEquals('', $df->formatNumber(5));
	}

	public function testParseReturnsFalseForNonNumericGarbage(): void
	{
		$hasExtraChars = false;
		$this->assertFalse(DecimalFormatter::parse('not-a-number', null, $hasExtraChars));
		$this->assertTrue($hasExtraChars);
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
		$parsed = DecimalFormatter::parse($input, null, $hasExtraChars);
		
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