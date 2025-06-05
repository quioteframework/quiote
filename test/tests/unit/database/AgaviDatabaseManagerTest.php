<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Testing\Attributes\AgaviIsolationEnvironment;
use Agavi\Database\AgaviDatabaseManager;

/**
 * @runTestsInSeparateProcesses
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[AgaviIsolationEnvironment('testing-use_database_on')]
class AgaviDatabaseManagerTest extends AgaviUnitTestCase
{
	private $_dbm = null;
	
	public function setUp(): void
	{
		// Call parent setUp to handle bootstrapping
		parent::setUp();
		
		$context = $this->getContext();
		$this->_dbm = $context->getDatabaseManager();
	}

	public function tearDown(): void
	{
		$this->_dbm = null;
	}

	public function testInitialization()
	{
		$this->assertInstanceOf(AgaviDatabaseManager::class, $this->_dbm);
	}

}
?>