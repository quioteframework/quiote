<?php

use Quiote\Console\Application;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises `routes:list` through the CLI harness (CommandTester). The
 * sandbox app's "web" context routing (SandboxRouting) is file-based
 * (generated from routing.xml), not attribute-based -- this deliberately
 * proves routes:list reads the app's actual configured Routing service
 * (Context::getInstance($context)->getRouting()) rather than only scanning
 * #[Route] attributes.
 */
final class RoutesListCommandTest extends PhpUnitTestCase
{
	private function tester(): CommandTester
	{
		$application = new Application();
		return new CommandTester($application->find('routes:list'));
	}

	public function testListsFileBasedRoutesFromTheConfiguredRoutingService(): void
	{
		$tester = $this->tester();
		$exitCode = $tester->execute(['--context' => 'web']);

		$this->assertSame(0, $exitCode);
		$display = $tester->getDisplay();
		$this->assertStringContainsString('index', $display);
		$this->assertStringContainsString('Default', $display);
	}

	public function testModuleFilterExcludesOtherModules(): void
	{
		$tester = $this->tester();
		$tester->execute(['--context' => 'web', '--module' => 'Default']);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('index', $display);
		$this->assertStringNotContainsString('Portal', $display);
	}

	public function testActionFilterExcludesOtherActions(): void
	{
		$tester = $this->tester();
		$tester->execute(['--context' => 'web', '--module' => 'Default', '--action' => 'Index']);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('index', $display);
		$this->assertStringNotContainsString('test_ticket_764', $display);
	}

	public function testJsonOutputIsValidAndContainsExpectedRoute(): void
	{
		$tester = $this->tester();
		$tester->execute(['--context' => 'web', '--module' => 'Default', '--json' => true]);

		$payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
		$this->assertArrayHasKey('routes', $payload);
		$this->assertArrayHasKey('diagnostics', $payload);
		$names = array_column($payload['routes'], 'name');
		$this->assertContains('index', $names);
	}

	public function testJsonOutputEnvelopeIncludesDiagnostics(): void
	{
		// "routes-list-cli-test" merges in the AttrRouting fixture module,
		// which is where the DUPLICATE_ROUTE_* fixtures used elsewhere in the
		// suite are declared -- here we only assert the envelope shape holds
		// even when the scan finds nothing to complain about.
		$tester = $this->tester();
		$tester->execute(['--context' => 'web', '--json' => true]);

		$payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
		$this->assertIsArray($payload['diagnostics']);
	}

	public function testRejectsUnknownSortOption(): void
	{
		$tester = $this->tester();
		$exitCode = $tester->execute(['--sort' => 'bogus']);

		$this->assertSame(1, $exitCode);
		$this->assertStringContainsString('Unknown --sort', $tester->getDisplay());
	}

	public function testSourceColumnDistinguishesFileFromAttributeRoutes(): void
	{
		// "routes-list-cli-test" context resolves to AttributeMergedRouting,
		// which merges the same file-based routes SandboxRouting uses with
		// the AttrRouting fixture module's #[Route] attributes -- see
		// tests/sandbox/app/Routing/AttributeMergedRouting.php.
		$tester = $this->tester();
		$tester->execute(['--context' => 'routes-list-cli-test', '--env' => 'testing', '--json' => true]);

		$payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
		$sourceByName = array_column($payload['routes'], 'source', 'name');

		$this->assertSame('File', $sourceByName['index']);
		$this->assertSame('Attribute', $sourceByName['attr_routing.list']);
	}
}
