<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\PluginConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

/**
 * Proves PluginConfigHandler::schema() matches its real toCanonicalArray()
 * output, and rejects the shape violations a hand-authored PHP/YAML
 * plugins file could plausibly contain.
 */
class PluginConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new PluginConfigHandler();

		$config = [
			['class' => 'App\\Plugin\\FooPlugin', 'enabled' => true],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testMissingEnabledIsAllowedStructurally(): void
	{
		// Hand-authored PHP/YAML may omit "enabled" (defaults to true) --
		// see the handler's own executeArray() docblock.
		$handler = new PluginConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['class' => 'App\\Plugin\\FooPlugin'],
		]);

		$this->assertSame([], $diagnostics);
	}

	public function testMissingClassIsReported(): void
	{
		$handler = new PluginConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['enabled' => true],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('[0].class', $diagnostics[0]->keyPath);
	}

	public function testNonBoolEnabledIsReported(): void
	{
		$handler = new PluginConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['class' => 'App\\Plugin\\FooPlugin', 'enabled' => 'true'],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('[0].enabled', $diagnostics[0]->keyPath);
	}

	public function testAssociativeArrayIsRejected(): void
	{
		$handler = new PluginConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'foo' => ['class' => 'App\\Plugin\\FooPlugin'],
		]);

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
	}

	public function testUnrecognizedKeyOnAPluginEntryIsReported(): void
	{
		$handler = new PluginConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['class' => 'App\\Plugin\\FooPlugin', 'enabeld' => true],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('[0].enabeld', $diagnostics[0]->keyPath);
	}
}
?>
