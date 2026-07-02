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
 * #[Route] attributes, per docs/ROUTING_AND_CLI_PLAN.md (B3).
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

		$routes = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
		$names = array_column($routes, 'name');
		$this->assertContains('index', $names);
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
		$tester->execute(['--context' => 'routes-list-cli-test', '--json' => true]);

		$routes = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
		$sourceByName = array_column($routes, 'source', 'name');

		$this->assertSame('File', $sourceByName['index']);
		$this->assertSame('Attribute', $sourceByName['attr_routing.list']);
	}
}
