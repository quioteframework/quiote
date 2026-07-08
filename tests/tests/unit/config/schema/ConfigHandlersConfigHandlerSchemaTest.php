<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\ConfigHandlersConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class ConfigHandlersConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new ConfigHandlersConfigHandler();

		$config = [
			'/app/Config/settings.xml' => [
				'class' => 'Quiote\\Config\\SettingConfigHandler',
				'parameters' => [],
				'transformations' => [],
				'validations' => [],
			],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testMissingClassFieldIsReported(): void
	{
		$handler = new ConfigHandlersConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'/app/Config/settings.xml' => ['parameters' => [], 'transformations' => [], 'validations' => []],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('/app/Config/settings.xml.class', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedKeyIsReported(): void
	{
		$handler = new ConfigHandlersConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'/app/Config/settings.xml' => [
				'class' => 'Quiote\\Config\\SettingConfigHandler',
				'parameters' => [],
				'transformations' => [],
				'validations' => [],
				'validaitons' => [],
			],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('/app/Config/settings.xml.validaitons', $diagnostics[0]->keyPath);
	}
}
?>
