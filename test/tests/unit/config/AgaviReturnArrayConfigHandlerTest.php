<?php

use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviReturnArrayConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class AgaviReturnArrayConfigHandlerTest extends ConfigHandlerTestBase
{
	public function testParseMixed()
	{
		$RACH = new AgaviReturnArrayConfigHandler();
		$document = $this->parseConfiguration(AgaviConfig::get('core.config_dir') . '/tests/rach_mixed.xml');
		$actual = $this->includeCode($RACH->execute($document));
		$expected = [
			'section1' => ['One' => 'A', 'Two' => 'B', 'Three' => 'C'], 
			'section2' => ['Three' => 'Z', 'Two' => 'Y', 'One' => 'X', 'value' => ''],
			'section3' => ['One' => '1', 'Three' => '3', 'Two' => '2']
		];
		$this->assertSame($expected, $actual);
	}


	public function testParseAttributes()
	{
		$RACH = new AgaviReturnArrayConfigHandler();
		$document = $this->parseConfiguration(AgaviConfig::get('core.config_dir') . '/tests/rach_attributes.xml');
		$actual = $this->includeCode($RACH->execute($document));
		$expected = [
			'section1' => ['One' => 'A', 'Two' => 'B', 'Three' => 'C', 'value' => ''], 
			'section2' => ['Three' => AgaviConfig::get('core.config_dir'), 'Two' => false, 'One' => true, 'value' => ''],
		];
		$this->assertSame($expected, $actual);
	}


	public function testParseTags()
	{
		$RACH = new AgaviReturnArrayConfigHandler();
		$document = $this->parseConfiguration(AgaviConfig::get('core.config_dir') . '/tests/rach_tags.xml');
		$actual = $this->includeCode($RACH->execute($document));
		$expected = [
			'section1' => ['One' => 'A', 'Two' => 'B', 'Three' => 'C'], 
			'section2' => ['Three' => 'Z', 'Two' => 'Y', 'One' => 'X'],
		];
		$this->assertSame($expected, $actual);
	}

	public function testParseComplex()
	{
		$RACH = new AgaviReturnArrayConfigHandler();
		$document = $this->parseConfiguration(AgaviConfig::get('core.config_dir') . '/tests/rach_complex.xml');
		$actual = $this->includeCode($RACH->execute($document));

		$expected = [
			'cachings' => [
				'Browse' => [
					'enabled' => true,
					'action' => AgaviConfig::get('core.app_dir'),
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
}
?>