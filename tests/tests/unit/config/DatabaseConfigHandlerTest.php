<?php

use Quiote\Config\Config;
use Quiote\Config\DatabaseConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class DCHTestDatabase
{
	public $params;

	public function initialize($dbm, $params)
	{
		$this->params = $params;
	}
}

class DatabaseConfigHandlerTest extends ConfigHandlerTestBase
{
	protected $databases;
	protected $defaultDatabaseName;

	#[\Override]
    public function setUp(): void
	{
		$this->databases = [];
	}
	
	protected function loadTestConfig($env = null) {
		$DBCH = new DatabaseConfigHandler();
		
		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/tests/databases.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/databases.xsl',
			$env
		);

		$this->includeCode($DBCH->execute($document));
		
	}

	public function testDatabaseConfigHandler()
	{
		$this->loadTestConfig();

		$this->assertInstanceOf('DCHTestDatabase', $this->databases['test1']);
		$paramsExpected = [
			'host' => 'localhost1',
			'user' => 'username1',
			'config' => Config::getString('core.app_dir') . '/Config/project-conf.php',
		];
		$this->assertSame($paramsExpected, $this->databases['test1']->params);

		$this->assertSame($this->databases['test1'], $this->databases[$this->defaultDatabaseName]);
	}

	public function testOverwrite()
	{
		$this->loadTestConfig('env2');

		$this->assertInstanceOf('DCHTestDatabase', $this->databases['test1']);
		$paramsExpected = [
			'host' => 'localhost1',
			'user' => 'testuser1',
			'config' => Config::getString('core.app_dir') . '/Config/project-conf.php',
		];
		$this->assertSame($paramsExpected, $this->databases['test1']->params);

		$this->assertSame($this->databases['test2'], $this->databases[$this->defaultDatabaseName]);
	}
	
	public function testMissingDefaultDoesNotReset() {
		// see https://github.com/quiote/quiote/issues/1533
		$this->loadTestConfig('missing-default-does-not-reset');

		$this->assertSame('test1', $this->defaultDatabaseName);
	}

	public function testDefaultDatabase() {
		$this->loadTestConfig('test-default');
		
		$this->assertSame('test2', $this->defaultDatabaseName);
	}

	public function testDefaultDatabase1_0() {
		$this->loadTestConfig('test-default-1.0');
		
		$this->assertSame('test1', $this->defaultDatabaseName);
	}
	
	public function testNonExistentDefault() {
		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$this->loadTestConfig('nonexistent-default');
	}
}
?>