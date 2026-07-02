<?php

use Quiote\Config\Config;
use Quiote\Console\Application;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves the bootstrap-in-console-context harness (AbstractAppCommand) works
 * before building routes:list/routes:compile on top of it -- see
 * docs/ROUTING_AND_CLI_PLAN.md (B2). core.app_dir is already set (readonly)
 * by tests/bootstrap.php, so this exercises the "already bootstrapped"
 * branch rather than app-dir resolution -- app-dir resolution itself has no
 * Quiote-specific state to fake convincingly under the shared test process.
 */
final class AboutCommandTest extends PhpUnitTestCase
{
	public function testAboutPrintsFrameworkAndAppInfo(): void
	{
		$application = new Application();
		$command = $application->find('about');
		$tester = new CommandTester($command);

		$exitCode = $tester->execute([]);

		$this->assertSame(0, $exitCode);
		$display = $tester->getDisplay();
		$this->assertStringContainsString(Config::get('core.app_dir'), $display);
		$this->assertStringContainsString(Config::get('core.environment'), $display);
		$this->assertStringContainsString(Config::get('core.module_dir'), $display);
	}
}
