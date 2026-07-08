<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\MiddlewareConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class MiddlewareConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new MiddlewareConfigHandler();

		$config = [
			['class' => 'App\\Middleware\\Foo', 'phase' => 'before_action', 'priority' => 10,
				'before' => null, 'after' => null, 'enabled' => true, 'override_framework' => false],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testMinimalEntryWithOnlyClassIsAllowed(): void
	{
		$handler = new MiddlewareConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['class' => 'App\\Middleware\\Foo'],
		]);

		$this->assertSame([], $diagnostics);
	}

	public function testMissingClassIsReported(): void
	{
		$handler = new MiddlewareConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['phase' => 'action'],
		]);

		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('[0].class', $diagnostics[0]->keyPath);
	}

	public function testInvalidPhaseIsReported(): void
	{
		$handler = new MiddlewareConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['class' => 'App\\Middleware\\Foo', 'phase' => 'during'],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.invalid_enum_value', $diagnostics[0]->code);
		$this->assertSame('[0].phase', $diagnostics[0]->keyPath);
	}

	public function testNonIntPriorityIsReported(): void
	{
		$handler = new MiddlewareConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['class' => 'App\\Middleware\\Foo', 'priority' => '10'],
		]);

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('[0].priority', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedKeyIsReported(): void
	{
		$handler = new MiddlewareConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			['class' => 'App\\Middleware\\Foo', 'proirity' => 10],
		]);

		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('[0].proirity', $diagnostics[0]->keyPath);
	}
}
?>
