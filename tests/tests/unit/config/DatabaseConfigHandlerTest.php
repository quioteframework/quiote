<?php

use Quiote\Config\Config;
use Quiote\Config\DatabaseConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class DCHTestDatabase
{
	/** @var array<string, mixed> */
	public array $params = [];

	/**
	 * @param array<string, mixed> $params
	 */
	public function initialize(mixed $dbm, array $params): void
	{
		$this->params = $params;
	}
}

class DatabaseConfigHandlerTest extends ConfigHandlerTestBase
{
	/** @var array<string, DCHTestDatabase> */
	protected $databases;
	protected ?string $defaultDatabaseName = null;

	#[\Override]
    public function setUp(): void
	{
		$this->databases = [];
	}

	protected function loadTestConfig(?string $env = null): void {
		$DBCH = new DatabaseConfigHandler();
		
		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/tests/databases.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/databases.xsl',
			$env
		);

		$this->includeCode($DBCH->execute($document));
		
	}

	public function testDatabaseConfigHandler(): void
	{
		$this->loadTestConfig();

		$this->assertInstanceOf('DCHTestDatabase', $this->databases['test1']);
		$paramsExpected = [
			'host' => 'localhost1',
			'user' => 'username1',
			'config' => Config::getString('core.app_dir') . '/Config/project-conf.php',
		];
		$this->assertSame($paramsExpected, $this->databases['test1']->params);

		$defaultDatabaseName = $this->defaultDatabaseName;
		if ($defaultDatabaseName === null) {
			$this->fail('DatabaseConfigHandler did not set a default database name.');
		}
		$this->assertSame($this->databases['test1'], $this->databases[$defaultDatabaseName]);
	}

	public function testOverwrite(): void
	{
		$this->loadTestConfig('env2');

		$this->assertInstanceOf('DCHTestDatabase', $this->databases['test1']);
		$paramsExpected = [
			'host' => 'localhost1',
			'user' => 'testuser1',
			'config' => Config::getString('core.app_dir') . '/Config/project-conf.php',
		];
		$this->assertSame($paramsExpected, $this->databases['test1']->params);

		$defaultDatabaseName = $this->defaultDatabaseName;
		if ($defaultDatabaseName === null) {
			$this->fail('DatabaseConfigHandler did not set a default database name.');
		}
		$this->assertSame($this->databases['test2'], $this->databases[$defaultDatabaseName]);
	}
	
	public function testMissingDefaultDoesNotReset(): void {
		// see https://github.com/quiote/quiote/issues/1533
		$this->loadTestConfig('missing-default-does-not-reset');

		$this->assertSame('test1', $this->defaultDatabaseName);
	}

	public function testDefaultDatabase(): void {
		$this->loadTestConfig('test-default');
		
		$this->assertSame('test2', $this->defaultDatabaseName);
	}

	public function testDefaultDatabase1_0(): void {
		$this->loadTestConfig('test-default-1.0');
		
		$this->assertSame('test1', $this->defaultDatabaseName);
	}
	
	public function testNonExistentDefault(): void {
		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$this->loadTestConfig('nonexistent-default');
	}

	public function testMissingDatabaseNameThrows(): void {
		$this->expectException(\Quiote\Exception\ParseException::class);
		$this->loadTestConfig('missing-name');
	}
}
?>