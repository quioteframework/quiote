<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Database\DatabaseManager;

/**
 * @runTestsInSeparateProcesses
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[IsolationEnvironment('testing-use_database_on')]
class DatabaseManagerTest extends UnitTestCase
{
	private $_dbm = null;
	
	public function setUp(): void
	{
		// Call parent setUp to handle bootstrapping
		parent::setUp();
		
		$context = $this->getContext();
		$this->_dbm = $context->getDatabaseManager();
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->_dbm = null;
	}

	public function testInitialization()
	{
		$this->assertInstanceOf(DatabaseManager::class, $this->_dbm);
	}

}
?>