<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\CompileConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class CompileConfigHandlerSchemaTest extends PhpUnitTestCase
{
	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new CompileConfigHandler();

		$this->assertSame([], SchemaValidator::validate($handler->schema(), [
			'/tmp/foo.php' => "echo 'X';",
		]));
	}

	public function testNonStringValueIsReported(): void
	{
		$handler = new CompileConfigHandler();

		$diagnostics = SchemaValidator::validate($handler->schema(), [
			'/tmp/foo.php' => ['not' => 'a string'],
		]);

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('/tmp/foo.php', $diagnostics[0]->keyPath);
	}
}
?>
