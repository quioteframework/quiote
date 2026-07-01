<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\Attributes\IsolationDefaultContext;
use Quiote\Config\Config;

/**
 * @runTestsInSeparateProcesses
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[IsolationEnvironment('testing.testIsolation')]
#[IsolationDefaultContext('web-isolated')]
class PhpUnitTestCaseTest extends PhpUnitTestCase
{
	/**
	 * Set up the test case
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->setIsolationEnvironment('testing.testIsolation'); // equivalent to the annotation @IsolationEnvironment on the testcase class
	}
	
	public function testIsolationEnvironment()
	{
		$this->assertEquals('testing.testIsolation', Config::get('testing.environment'));
	}
	
	/**
	 * Test method with method-level isolation environment attribute
	 */
	#[IsolationEnvironment('testing.testIsolationAnnotated')]
	public function testIsolationEnvironmentAnnotated()
	{
		$this->assertEquals('testing.testIsolationAnnotated', Config::get('testing.environment'));
	}
	
	public function testIsolationDefaultContext()
	{
		$this->assertEquals('web-isolated', Config::get('core.default_context'));
	}
	
	/**
	 * Test method with method-level isolation default context attribute
	 */
	#[IsolationDefaultContext('web-isolated-annotated-method')]
	public function testIsolationDefaultContextAnnotated()
	{
		$this->assertEquals('web-isolated-annotated-method', Config::get('core.default_context'));
	}
	
	/**
	 * @preserveGlobalState enabled
	 */
	public function testPreserveGlobalStateOnWorks() {
		// this test just needs to run to signal success
		$this->assertTrue(true);
	}

	/**
	 * @preserveGlobalState disabled
	 */
	public function testPreserveGlobalStateOffWorks() {
		// this test just needs to run to signal success
		$this->assertTrue(true);
	}
	
}

?>