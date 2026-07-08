<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\ModuleConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class ModuleConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new ModuleConfigHandler();

		$config = [
			'enabled' => true,
			'settings' => ['modules.${moduleName}.foo' => 'value'],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testMissingEnabledIsReported(): void
	{
		$handler = new ModuleConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), ['settings' => []]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('enabled', $diagnostics[0]->keyPath);
	}

	public function testNonBoolEnabledIsReported(): void
	{
		$handler = new ModuleConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), ['enabled' => 'yes', 'settings' => []]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('enabled', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedTopLevelKeyIsReported(): void
	{
		$handler = new ModuleConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'enabled' => true,
			'settings' => [],
			'enalbed' => true,
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('enalbed', $diagnostics[0]->keyPath);
	}

	public function testSettingsMustBeAMap(): void
	{
		$handler = new ModuleConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), ['enabled' => true, 'settings' => 'nope']);

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('settings', $diagnostics[0]->keyPath);
	}
}
?>
