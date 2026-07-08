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
	private DatabaseManager $_dbm;
	
	public function setUp(): void
	{
		// Call parent setUp to handle bootstrapping
		parent::setUp();
		
		$context = $this->getContext();
		$dbm = $context->getDatabaseManager();
		$this->assertNotNull($dbm, 'core.use_database is expected to be on for this isolation environment');
		$this->_dbm = $dbm;
	}

	#[\Override]
    public function tearDown(): void
	{
		unset($this->_dbm);
	}

	public function testInitialization(): void
	{
		$this->assertInstanceOf(DatabaseManager::class, $this->_dbm);
	}

}
?>