<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\DatabaseConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

/**
 * Proves DatabaseConfigHandler::schema() matches its real toCanonicalArray()
 * output, and rejects the shape violations a hand-authored PHP/YAML
 * databases file could plausibly contain.
 */
class DatabaseConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new DatabaseConfigHandler();

		$config = [
			'default' => 'main',
			'databases' => [
				'main' => ['class' => 'eloquent', 'parameters' => ['dsn' => 'sqlite::memory:']],
			],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testMissingDatabasesKeyIsReported(): void
	{
		$handler = new DatabaseConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), ['default' => 'main']);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('databases', $diagnostics[0]->keyPath);
	}

	public function testDatabaseEntryMissingClassIsReported(): void
	{
		$handler = new DatabaseConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'default' => 'main',
			'databases' => ['main' => ['parameters' => []]],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('databases.main.class', $diagnostics[0]->keyPath);
	}

	public function testNullDefaultIsAllowedStructurally(): void
	{
		// "default must reference an existing database" is a cross-field
		// check that stays in executeArray(), not a structural violation.
		$handler = new DatabaseConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'default' => null,
			'databases' => ['main' => ['class' => 'eloquent', 'parameters' => []]],
		]);

		$this->assertSame([], $diagnostics);
	}

	public function testUnrecognizedTopLevelKeyIsReported(): void
	{
		$handler = new DatabaseConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'default' => 'main',
			'databases' => ['main' => ['class' => 'eloquent', 'parameters' => []]],
			'defualt' => 'main',
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('defualt', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedKeyOnADatabaseEntryIsReported(): void
	{
		$handler = new DatabaseConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'default' => 'main',
			'databases' => ['main' => ['class' => 'eloquent', 'parameters' => [], 'classs' => 'typo']],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('databases.main.classs', $diagnostics[0]->keyPath);
	}
}
?>
