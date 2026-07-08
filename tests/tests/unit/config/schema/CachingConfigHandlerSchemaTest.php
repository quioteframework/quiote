<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\CachingConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class CachingConfigHandlerSchemaTest extends PhpUnitTestCase
{
	/**
	 * @return array{'*': array{
	 *   lifetime: string,
	 *   groups: list<array{name: string, source: string, namespace: null}>,
	 *   views: null,
	 *   action_attributes: array<empty, empty>,
	 *   output_types: array{html: array{
	 *     layers: array{main: bool},
	 *     template_variables: array<empty, empty>,
	 *     request_attributes: list<array{name: string, namespace: null}>,
	 *     request_attribute_namespaces: array<empty, empty>,
	 *   }},
	 * }}
	 */
	private function cleanConfig(): array
	{
		return [
			'*' => [
				'lifetime' => '3600',
				'groups' => [
					['name' => 'foo', 'source' => 'string', 'namespace' => null],
				],
				'views' => null,
				'action_attributes' => [],
				'output_types' => [
					'html' => [
						'layers' => ['main' => true],
						'template_variables' => [],
						'request_attributes' => [
							['name' => 'id', 'namespace' => null],
						],
						'request_attribute_namespaces' => [],
					],
				],
			],
		];
	}

	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new CachingConfigHandler();

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $this->cleanConfig()));
	}

	public function testMissingFieldOnACachingEntryIsReported(): void
	{
		$handler = new CachingConfigHandler();

		$config = $this->cleanConfig();
		unset($config['*']['action_attributes']);

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('*.action_attributes', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedKeyOnAnOutputTypeEntryIsReported(): void
	{
		$handler = new CachingConfigHandler();

		$config = $this->cleanConfig();
		$config['*']['output_types']['html']['layer'] = [];

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('*.output_types.html.layer', $diagnostics[0]->keyPath);
	}

	public function testGroupsMustBeAList(): void
	{
		$handler = new CachingConfigHandler();

		$config = $this->cleanConfig();
		$config['*']['groups'] = 'nope';

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('*.groups', $diagnostics[0]->keyPath);
	}

	public function testNullViewsIsAllowed(): void
	{
		$handler = new CachingConfigHandler();

		$config = $this->cleanConfig();
		$config['*']['views'] = null;

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}
}
?>
