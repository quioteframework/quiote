<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\OutputTypeConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class OutputTypeConfigHandlerSchemaTest extends PhpUnitTestCase
{
	/**
	 * @return array{
	 *   default: string,
	 *   output_types: array{html: array{
	 *     parameters: array<empty, empty>,
	 *     default_renderer: string,
	 *     renderers: array{php: array{class: string, instance: null, parameters: array<empty, empty>}},
	 *     layouts: array{main: array{
	 *       layers: array{content: array{
	 *         class: string,
	 *         parameters: array<empty, empty>,
	 *         renderer: null,
	 *         slots: array{body: array{action: null, module: null, output_type: null, request_method: null, parameters: array<empty, empty>}},
	 *       }},
	 *       parameters: array<empty, empty>,
	 *     }},
	 *     default_layout: string,
	 *     exception_template: null,
	 *   }},
	 * }
	 */
	private function cleanConfig(): array
	{
		return [
			'default' => 'html',
			'output_types' => [
				'html' => [
					'parameters' => [],
					'default_renderer' => 'php',
					'renderers' => [
						'php' => ['class' => 'Quiote\\Renderer\\PhpRenderer', 'instance' => null, 'parameters' => []],
					],
					'layouts' => [
						'main' => [
							'layers' => [
								'content' => [
									'class' => 'Quiote\\View\\FileTemplateLayer',
									'parameters' => [],
									'renderer' => null,
									'slots' => [
										'body' => ['action' => null, 'module' => null, 'output_type' => null, 'request_method' => null, 'parameters' => []],
									],
								],
							],
							'parameters' => [],
						],
					],
					'default_layout' => 'main',
					'exception_template' => null,
				],
			],
		];
	}

	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new OutputTypeConfigHandler();

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $this->cleanConfig()));
	}

	public function testMissingDefaultLayoutIsReported(): void
	{
		$handler = new OutputTypeConfigHandler();

		$config = $this->cleanConfig();
		unset($config['output_types']['html']['default_layout']);

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('output_types.html.default_layout', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedKeyOnASlotIsReported(): void
	{
		$handler = new OutputTypeConfigHandler();

		$config = $this->cleanConfig();
		$config['output_types']['html']['layouts']['main']['layers']['content']['slots']['body']['requset_method'] = null;

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('output_types.html.layouts.main.layers.content.slots.body.requset_method', $diagnostics[0]->keyPath);
	}

	public function testInvalidLayerClassIsReported(): void
	{
		$handler = new OutputTypeConfigHandler();

		$config = $this->cleanConfig();
		$config['output_types']['html']['layouts']['main']['layers']['content']['class'] = '1Invalid';

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.invalid_php_class', $diagnostics[0]->code);
		$this->assertSame('output_types.html.layouts.main.layers.content.class', $diagnostics[0]->keyPath);
	}
}
?>
