<?php

use Quiote\Introspection\AppIntrospectionCompiler;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Testing\PhpUnitTestCase;

final class AppIntrospectionCompilerTest extends PhpUnitTestCase
{
	public function testCompileProducesTheDocumentedTopLevelShape(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$this->assertSame(1, $artifact['_schema_version']);
		$this->assertNotSame('', $artifact['source_hash']);
		foreach (['modules', 'routes', 'triads', 'diagnostics', 'dependencies', 'shadowed'] as $key) {
			$this->assertArrayHasKey($key, $artifact);
		}
	}

	public function testCompileIncludesTheSandboxDefaultModuleAndItsIndexAction(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$moduleNames = array_column($artifact['modules'], 'name');
		$this->assertContains('Default', $moduleNames);

		$defaultModule = null;
		foreach ($artifact['modules'] as $module) {
			if ($module['name'] === 'Default') {
				$defaultModule = $module;
			}
		}
		$this->assertNotNull($defaultModule);
		$this->assertContains('Index', $defaultModule['actions']);
	}

	public function testCompileResolvesAKnownGoodTriadWithNoDiagnostic(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$triad = null;
		foreach ($artifact['triads'] as $candidate) {
			if ($candidate['module'] === 'Default' && $candidate['action'] === 'Index') {
				$triad = $candidate;
			}
		}
		$this->assertNotNull($triad);
		$this->assertStringEndsWith('IndexAction.php', $triad['actionFile']);
		$this->assertNotNull($triad['viewFile']);
		$this->assertStringEndsWith('IndexSuccessView.php', $triad['viewFile']);

		$verbNames = array_column($triad['verbs'], 'name');
		$this->assertContains('executeRead', $verbNames);

		foreach ($artifact['diagnostics'] as $diagnostic) {
			$this->assertNotSame('Default.Index', $diagnostic['symbol']);
		}
	}

	public function testCompileListsRoutesFromTheConfiguredRoutingService(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		$names = array_column($artifact['routes'], 'name');
		$this->assertContains('index', $names);
	}

	public function testDiagnosticsSurviveTheArrayRoundTripWithDiagnosticToArrayShape(): void
	{
		$artifact = (new AppIntrospectionCompiler())->compile('web');

		foreach ($artifact['diagnostics'] as $diagnostic) {
			foreach (['severity', 'code', 'message', 'file', 'line', 'column', 'endLine', 'endColumn', 'symbol'] as $key) {
				$this->assertArrayHasKey($key, $diagnostic);
			}
			$this->assertContains($diagnostic['severity'], [Diagnostic::SEVERITY_WARNING, Diagnostic::SEVERITY_ERROR]);
		}
	}
}
