<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\FactoryConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

/**
 * Proves FactoryConfigHandler::schema() matches its real toCanonicalArray()
 * output, and rejects the shape violations a hand-authored PHP/YAML factories
 * file could plausibly contain.
 */
class FactoryConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new FactoryConfigHandler();

		$config = [
			'validation_manager' => ['class' => 'Quiote\\Validator\\ValidationManager', 'params' => []],
			'response' => ['class' => 'Quiote\\Response\\Response', 'params' => ['foo' => 'bar']],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testUnrecognizedFactoryNameIsReported(): void
	{
		$handler = new FactoryConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'resposne' => ['class' => 'Quiote\\Response\\Response', 'params' => []],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('resposne', $diagnostics[0]->keyPath);
	}

	public function testWrongTypedClassIsReported(): void
	{
		$handler = new FactoryConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'response' => ['class' => 123, 'params' => []],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.invalid_php_class', $diagnostics[0]->code);
		$this->assertSame('response.class', $diagnostics[0]->keyPath);
	}

	public function testNullClassIsAllowedStructurally(): void
	{
		// A missing/incomplete entry is a Layer-2 (required-ness) concern
		// handled by executeArray(), not a structural violation.
		$handler = new FactoryConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'response' => ['class' => null, 'params' => []],
		]);

		$this->assertSame([], $diagnostics);
	}
}
?>
