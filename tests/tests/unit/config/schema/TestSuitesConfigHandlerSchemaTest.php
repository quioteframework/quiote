<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\TestSuitesConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class TestSuitesConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new TestSuitesConfigHandler();

		$config = [
			'unit' => ['class' => 'TestSuite', 'base' => 'tests/', 'includes' => [], 'excludes' => [], 'testfiles' => []],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testMissingFieldIsReported(): void
	{
		$handler = new TestSuitesConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'unit' => ['class' => 'TestSuite', 'base' => 'tests/', 'includes' => [], 'excludes' => []],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('unit.testfiles', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedKeyIsReported(): void
	{
		$handler = new TestSuitesConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'unit' => ['class' => 'TestSuite', 'base' => 'tests/', 'includes' => [], 'excludes' => [], 'testfiles' => [], 'clas' => 'x'],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('unit.clas', $diagnostics[0]->keyPath);
	}
}
?>
