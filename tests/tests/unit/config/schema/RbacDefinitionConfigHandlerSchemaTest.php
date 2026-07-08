<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\RbacDefinitionConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class RbacDefinitionConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new RbacDefinitionConfigHandler();

		$config = [
			'admin' => ['parent' => null, 'permissions' => ['manage_users']],
			'editor' => ['parent' => 'admin', 'permissions' => []],
		];

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $config));
	}

	public function testMissingPermissionsIsReported(): void
	{
		$handler = new RbacDefinitionConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'admin' => ['parent' => null],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('admin.permissions', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedKeyOnARoleIsReported(): void
	{
		$handler = new RbacDefinitionConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'admin' => ['parent' => null, 'permissions' => [], 'parentt' => null],
		]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('admin.parentt', $diagnostics[0]->keyPath);
	}

	public function testPermissionsMustBeAList(): void
	{
		$handler = new RbacDefinitionConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'admin' => ['parent' => null, 'permissions' => ['x' => 'y']],
		]);

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('admin.permissions', $diagnostics[0]->keyPath);
	}
}
?>
