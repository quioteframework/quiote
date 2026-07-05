<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\OutputTypeConfigHandler;
use Quiote\Exception\ConfigurationException;

/**
 * Confirms that OutputTypeConfigHandler::executeArray() applies the same
 * defaults that XML provides via getAttribute($name, $default), so PHP/YAML
 * output-type configs can be terse (only required keys present).
 */
class OutputTypeConfigHandlerFormatDriverTest extends PhpUnitTestCase
{
	private OutputTypeConfigHandler $handler;

	protected function setUp(): void
	{
		parent::setUp();
		$this->handler = new OutputTypeConfigHandler();
		$this->handler->initialize(null, []);
	}

	public function testMinimalOutputTypeWithOnlyRequiredKeysCompiles(): void
	{
		$config = [
			'default' => 'html',
			'output_types' => [
				'html' => [
					'renderers' => [
						'php' => ['class' => 'Quiote\Renderer\PhpRenderer'],
					],
					'default_renderer' => 'php',
				],
			],
		];

		$code = $this->handler->executeArray($config, 'output_types.php');

		$this->assertStringContainsString('html', $code);
		$this->assertStringContainsString('PhpRenderer', $code);
		$this->assertStringContainsString('$this->defaultOutputType', $code);
	}

	public function testAbsentOptionalKeysDefaultToEmptyArraysAndNulls(): void
	{
		$config = [
			'default' => 'json',
			'output_types' => [
				'json' => [
					'renderers' => [
						'php' => ['class' => 'Quiote\Renderer\PhpRenderer'],
					],
					'default_renderer' => 'php',
				],
			],
		];

		$code = $this->handler->executeArray($config, 'output_types.php');

		// layouts defaults to []
		$this->assertStringContainsString("array (", $code);
		// default_layout defaults to null
		$this->assertStringContainsString('NULL', $code);
		// exception_template defaults to null
		$this->assertStringContainsString('$this->defaultOutputType = \'json\'', $code);
	}

	public function testRendererWithoutInstanceKeyGetsNullDefault(): void
	{
		$config = [
			'default' => 'html',
			'output_types' => [
				'html' => [
					'renderers' => [
						'php' => ['class' => 'Quiote\Renderer\PhpRenderer'],
					],
					'default_renderer' => 'php',
				],
			],
		];

		$code = $this->handler->executeArray($config, 'output_types.php');

		// 'instance' key must appear in the compiled renderer array as NULL
		$this->assertStringContainsString("'instance' => NULL", $code);
	}

	public function testLayerInLayoutDefaultsApplied(): void
	{
		$config = [
			'default' => 'html',
			'output_types' => [
				'html' => [
					'renderers' => [
						'php' => ['class' => 'Quiote\Renderer\PhpRenderer'],
					],
					'default_renderer' => 'php',
					'layouts' => [
						'default' => [
							'layers' => [
								'content' => [],
							],
						],
					],
					'default_layout' => 'default',
				],
			],
		];

		$code = $this->handler->executeArray($config, 'output_types.php');

		// layer class defaults to FileTemplateLayer
		$this->assertStringContainsString('FileTemplateLayer', $code);
		// slots defaults to empty array
		$this->assertStringContainsString("'slots' =>", $code);
	}

	public function testUndefinedDefaultOutputTypeThrows(): void
	{
		$config = [
			'default' => 'missing',
			'output_types' => [
				'html' => ['renderers' => ['php' => ['class' => 'Quiote\Renderer\PhpRenderer']], 'default_renderer' => 'php'],
			],
		];

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('undefined default Output Type "missing"');
		$this->handler->executeArray($config, 'output_types.php');
	}

	public function testNullDefaultThrows(): void
	{
		$config = [
			'default' => null,
			'output_types' => [
				'html' => ['renderers' => ['php' => ['class' => 'Quiote\Renderer\PhpRenderer']], 'default_renderer' => 'php'],
			],
		];

		$this->expectException(ConfigurationException::class);
		$this->handler->executeArray($config, 'output_types.php');
	}

	public function testMultipleOutputTypesAllCompile(): void
	{
		$config = [
			'default' => 'html',
			'output_types' => [
				'html' => [
					'renderers' => ['php' => ['class' => 'Quiote\Renderer\PhpRenderer']],
					'default_renderer' => 'php',
					'parameters' => ['Content-Type' => 'text/html; charset=UTF-8'],
				],
				'json' => [
					'renderers' => ['php' => ['class' => 'Quiote\Renderer\PhpRenderer']],
					'default_renderer' => 'php',
				],
			],
		];

		$code = $this->handler->executeArray($config, 'output_types.php');

		$this->assertStringContainsString("'html'", $code);
		$this->assertStringContainsString("'json'", $code);
		$this->assertStringContainsString('text/html', $code);
	}
}
?>
