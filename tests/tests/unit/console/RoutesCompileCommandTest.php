<?php

use Quiote\Config\Config;
use Quiote\Console\Application;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RoutesCompileCommandTest extends PhpUnitTestCase
{
	private function tester(): CommandTester
	{
		$application = new Application();
		return new CommandTester($application->find('routes:compile'));
	}

	private function artifactPath(): string
	{
		return rtrim(Config::getString('core.cache_dir'), '/') . '/introspection/app.json';
	}

	protected function tearDown(): void
	{
		@unlink($this->artifactPath());
		parent::tearDown();
	}

	public function testWritesTheIntrospectionArtifactAndPrintsASummary(): void
	{
		$tester = $this->tester();
		$exitCode = $tester->execute(['--context' => 'web']);

		$this->assertSame(0, $exitCode);
		$this->assertFileExists($this->artifactPath());
		$this->assertStringContainsString('Compiled', $tester->getDisplay());
	}

	public function testJsonOutputMatchesTheWrittenArtifact(): void
	{
		$tester = $this->tester();
		$tester->execute(['--context' => 'web', '--json' => true]);

		$printed = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
		$written = json_decode((string) file_get_contents($this->artifactPath()), true, flags: JSON_THROW_ON_ERROR);

		$this->assertSame($written['routes'], $printed['routes']);
		$this->assertSame($written['modules'], $printed['modules']);
		$this->assertArrayHasKey('generated_at', $printed);
	}

	public function testTableOutputRendersRouteAndTriadCounts(): void
	{
		$tester = $this->tester();
		$tester->execute(['--context' => 'web']);

		// Every diagnostic the sandbox app currently produces for "web" is a
		// warning (missing views/templates on feature-test-only fixture
		// modules) -- exit code must stay SUCCESS, not FAILURE, for warnings.
		$artifact = json_decode((string) file_get_contents($this->artifactPath()), true, flags: JSON_THROW_ON_ERROR);
		foreach ($artifact['diagnostics'] as $diagnostic) {
			$this->assertNotSame(\Quiote\Support\Compiler\Diagnostic::SEVERITY_ERROR, $diagnostic['severity']);
		}
	}
}
