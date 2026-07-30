<?php

use Quiote\Config\Config;
use Quiote\Config\ReturnArrayConfigHandler;
use Quiote\Exception\ConfigurationException;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class ReturnArrayConfigHandlerTest extends ConfigHandlerTestBase
{
	public function testParseMixed(): void
	{
		$RACH = new ReturnArrayConfigHandler();
		$document = $this->parseConfiguration(Config::getString('core.config_dir') . '/tests/rach_mixed.xml');
		$actual = $this->includeCode($RACH->execute($document));
		$expected = [
			'section1' => ['One' => 'A', 'Two' => 'B', 'Three' => 'C'], 
			'section2' => ['Three' => 'Z', 'Two' => 'Y', 'One' => 'X', 'value' => ''],
			'section3' => ['One' => 1, 'Three' => 3, 'Two' => 2]
		];
		$this->assertSame($expected, $actual);
	}


	public function testParseAttributes(): void
	{
		$RACH = new ReturnArrayConfigHandler();
		$document = $this->parseConfiguration(Config::getString('core.config_dir') . '/tests/rach_attributes.xml');
		$actual = $this->includeCode($RACH->execute($document));
		$expected = [
			'section1' => ['One' => 'A', 'Two' => 'B', 'Three' => 'C', 'value' => ''], 
			'section2' => ['Three' => Config::getString('core.config_dir'), 'Two' => false, 'One' => true, 'value' => ''],
		];
		$this->assertSame($expected, $actual);
	}


	public function testParseTags(): void
	{
		$RACH = new ReturnArrayConfigHandler();
		$document = $this->parseConfiguration(Config::getString('core.config_dir') . '/tests/rach_tags.xml');
		$actual = $this->includeCode($RACH->execute($document));
		$expected = [
			'section1' => ['One' => 'A', 'Two' => 'B', 'Three' => 'C'], 
			'section2' => ['Three' => 'Z', 'Two' => 'Y', 'One' => 'X'],
		];
		$this->assertSame($expected, $actual);
	}

	public function testParseComplex(): void
	{
		$RACH = new ReturnArrayConfigHandler();
		$document = $this->parseConfiguration(Config::getString('core.config_dir') . '/tests/rach_complex.xml');
		$actual = $this->includeCode($RACH->execute($document));

		$expected = [
			'cachings' => [
				'Browse' => [
					'enabled' => true,
					'action' => Config::getString('core.app_dir'),
					'groups' => [
						'foo' => 'bar',
						'categories' => '',
						'id' => [
							'source' => 'request.parameter',
							'value' => '',
						],
						'LANG' => [
							'source' => 'constant',
							'value' => '',
						],
						'admin' => [
							'source' => 'user.credential',
							'value' => '',
						],
					],
					'decorator' => [
						'include' => false,
						'slots' => [
							'breadcrumb',
						],
						'variables' => [
							'bar' => 'baz',
							'_title',
							'_section',
						],
					],
					'variables' => [
						'categoryId' => [
							'source' => 'request.attribute',
							'value' => '',
						],
						'isRootCat' => [
							'source' => 'request.attribute',
							'value' => '',
						],
					],
				],
			],
		];
		$this->assertEquals($expected, $actual);
	}

	/**
	 * Exercises the two bucketing paths in convertToArray(): duplicate child
	 * elements that carry an id attribute get keyed into a synthetic pluralized
	 * container (id_attribute path), while duplicates without one get appended
	 * into a synthetic pluralized list (append path).
	 */
	public function testParseGroupsDuplicateChildrenIntoSyntheticBuckets(): void
	{
		$RACH = new ReturnArrayConfigHandler();
		$document = $this->parseConfiguration(Config::getString('core.config_dir') . '/tests/rach_buckets.xml');
		$actual = $this->includeCode($RACH->execute($document));
		$expected = [
			'container' => [
				'solo' => 'lonely',
				'items' => ['a' => 1, 'b' => 2],
			],
			'duplicates' => [
				'entries' => ['first', 'second'],
			],
		];
		$this->assertSame($expected, $actual);
	}

	public function testConvertToArrayThrowsWhenIdAttributeParameterIsNotAString(): void
	{
		$RACH = new ReturnArrayConfigHandler();
		$RACH->setParameter('id_attribute', ['not', 'a', 'string']);
		$document = $this->parseConfiguration(Config::getString('core.config_dir') . '/tests/rach_mixed.xml');

		$this->expectException(ConfigurationException::class);
		$RACH->execute($document);
	}
}
?>