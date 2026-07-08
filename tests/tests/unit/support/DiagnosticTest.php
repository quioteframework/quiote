<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Support\Compiler\Diagnostic;

/**
 * Position fields (line/column/endLine/endColumn/symbol) are all optional
 * and nullable -- existing call sites across AttributeRouteScanner,
 * ValidatorPlanBuilder, MiddlewareAttributeScanner, etc. construct a
 * Diagnostic with just (severity, code, message, where) and must keep
 * working unchanged.
 */
class DiagnosticTest extends PhpUnitTestCase
{
	public function testPositionFieldsDefaultToNullWhenOmitted(): void
	{
		$diagnostic = new Diagnostic(Diagnostic::SEVERITY_ERROR, 'SOME_CODE', 'A message', '/app/foo.php');

		$this->assertNull($diagnostic->line);
		$this->assertNull($diagnostic->column);
		$this->assertNull($diagnostic->endLine);
		$this->assertNull($diagnostic->endColumn);
		$this->assertNull($diagnostic->symbol);
	}

	public function testPositionFieldsAreStoredWhenProvided(): void
	{
		$diagnostic = new Diagnostic(
			Diagnostic::SEVERITY_WARNING,
			'SOME_CODE',
			'A message',
			'/app/foo.php',
			line: 12,
			column: 4,
			endLine: 12,
			endColumn: 20,
			symbol: 'foo.bar',
		);

		$this->assertSame(12, $diagnostic->line);
		$this->assertSame(4, $diagnostic->column);
		$this->assertSame(12, $diagnostic->endLine);
		$this->assertSame(20, $diagnostic->endColumn);
		$this->assertSame('foo.bar', $diagnostic->symbol);
	}

	public function testShadowedConfigCodeConstantExists(): void
	{
		$this->assertSame('SHADOWED_CONFIG', Diagnostic::CODE_SHADOWED_CONFIG);
	}
}
?>
