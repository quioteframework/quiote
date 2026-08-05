<?php

use Quiote\Config\CompiledArtifact;
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

		$this->assertStringContainsString('html', var_export($code, true));
		$this->assertStringContainsString('PhpRenderer', var_export($code, true));
		$this->assertStringContainsString("'default' =>", var_export($code, true));
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
		$this->assertStringContainsString("array (", var_export($code, true));
		// default_layout defaults to null
		$this->assertStringContainsString('NULL', var_export($code, true));
		// exception_template defaults to null
		$this->assertStringContainsString("'default' => 'json'", var_export($code, true));
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
		$this->assertStringContainsString("'instance' => NULL", var_export($code, true));
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
		$this->assertStringContainsString('FileTemplateLayer', var_export($code, true));
		// slots defaults to empty array
		$this->assertStringContainsString("'slots' =>", var_export($code, true));
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

		$this->assertStringContainsString("'html'", var_export($code, true));
		$this->assertStringContainsString("'json'", var_export($code, true));
		$this->assertStringContainsString('text/html', var_export($code, true));
	}

	/**
	 * The property the redesign exists for: the compiled output cannot reach into whatever
	 * includes it.
	 */
	public function testTheCompiledOutputNeverAssignsIntoItsIncluder(): void
	{
		$code = $this->handler->executeArray([
			'default' => 'html',
			'output_types' => [
				'html' => [
					'renderers' => ['php' => ['class' => 'Quiote\\Renderer\\PhpRenderer']],
					'default_renderer' => 'php',
					'parameters' => ['Content-Type' => 'text/html'],
				],
			],
		], 'output_types.php');

		$source = CompiledArtifact::source($code, 'output_types.php', $this->handler::class);
		$this->assertStringNotContainsString('$this->', $source);
		$this->assertStringNotContainsString('new Quiote\\Controller\\OutputType()', $source);
		$this->assertStringContainsString('return ', $source);
	}
}
?>
