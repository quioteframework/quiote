<?php

use Quiote\Config\CompiledArtifact;
use Quiote\Config\Config;
use Quiote\Config\DatabaseConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

/**
 * The handler's contract is the declaration it emits. It no longer generates statements that
 * assign into whatever includes them, so these read the returned value instead of inspecting
 * properties on a test case standing in for a DatabaseManager.
 *
 * Validating a declaration -- rejecting a missing class, a class that is not a Database, a default
 * naming a connection that was not declared -- belongs to DatabaseDefinitions and is tested there.
 */
class DatabaseConfigHandlerTest extends ConfigHandlerTestBase
{
	/**
	 * @return array{databases: array<string, array{class: string, parameters: array<string, mixed>}>, default: string}
	 */
	protected function loadTestConfig(?string $env = null): array
	{
		$DBCH = new DatabaseConfigHandler();

		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/tests/databases.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/databases.xsl',
			$env
		);

		$compiled = $DBCH->execute($document);
		$this->assertIsArray($compiled);
		$this->assertArrayHasKey('databases', $compiled);
		$this->assertArrayHasKey('default', $compiled);
		$this->assertIsArray($compiled['databases']);
		$this->assertIsString($compiled['default']);

		/** @var array{databases: array<string, array{class: string, parameters: array<string, mixed>}>, default: string} $compiled */
		return $compiled;
	}

	public function testDatabaseConfigHandlerDeclaresEachConnection(): void
	{
		$compiled = $this->loadTestConfig();

		$this->assertSame(
			[
				'host' => 'localhost1',
				'user' => 'username1',
				'config' => Config::getString('core.app_dir') . '/Config/project-conf.php',
			],
			$compiled['databases']['test1']['parameters'],
		);
		$this->assertSame('test1', $compiled['default']);
		$this->assertArrayHasKey($compiled['default'], $compiled['databases']);
	}

	public function testOverwrite(): void
	{
		$compiled = $this->loadTestConfig('env2');

		$this->assertSame(
			[
				'host' => 'localhost1',
				'user' => 'testuser1',
				'config' => Config::getString('core.app_dir') . '/Config/project-conf.php',
			],
			$compiled['databases']['test1']['parameters'],
		);
		$this->assertSame('test2', $compiled['default']);
		$this->assertArrayHasKey($compiled['default'], $compiled['databases']);
	}

	public function testMissingDefaultDoesNotReset(): void
	{
		// see https://github.com/quiote/quiote/issues/1533
		$this->assertSame('test1', $this->loadTestConfig('missing-default-does-not-reset')['default']);
	}

	public function testDefaultDatabase(): void
	{
		$this->assertSame('test2', $this->loadTestConfig('test-default')['default']);
	}

	public function testDefaultDatabase1_0(): void
	{
		$this->assertSame('test1', $this->loadTestConfig('test-default-1.0')['default']);
	}

	public function testNonExistentDefault(): void
	{
		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$this->loadTestConfig('nonexistent-default');
	}

	public function testMissingDatabaseNameThrows(): void
	{
		$this->expectException(\Quiote\Exception\ParseException::class);
		$this->loadTestConfig('missing-name');
	}

	/**
	 * The property the redesign exists for: the compiled output cannot reach into whatever
	 * includes it.
	 */
	public function testTheCompiledOutputNeverAssignsIntoItsIncluder(): void
	{
		$DBCH = new DatabaseConfigHandler();

		$code = $DBCH->executeArray([
			'default' => 'main',
			'databases' => [
				'main' => ['class' => \Quiote\Database\PdoDatabase::class, 'parameters' => ['dsn' => 'sqlite::memory:']],
			],
		], 'tests/databases.xml');

		$source = CompiledArtifact::source($code, 'tests/databases.xml', $DBCH::class);
		$this->assertStringNotContainsString('$this->', $source);
		$this->assertStringNotContainsString('$database = new', $source);
		$this->assertStringContainsString('return ', $source);
	}
}
