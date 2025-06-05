<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Testing\Attributes\AgaviIsolationEnvironment;
use Agavi\Testing\Attributes\AgaviIsolationDefaultContext;
use Agavi\Config\AgaviConfig;

/**
 * @runTestsInSeparateProcesses
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[AgaviIsolationEnvironment('testing.testIsolation')]
#[AgaviIsolationDefaultContext('web-isolated')]
class AgaviPhpUnitTestCaseTest extends AgaviPhpUnitTestCase
{
	/**
	 * Set up the test case
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->setIsolationEnvironment('testing.testIsolation'); // equivalent to the annotation @AgaviIsolationEnvironment on the testcase class
	}
	
	public function testIsolationEnvironment()
	{
		$this->assertEquals('testing.testIsolation', AgaviConfig::get('testing.environment'));
	}
	
	/**
	 * Test method with method-level isolation environment attribute
	 */
	#[AgaviIsolationEnvironment('testing.testIsolationAnnotated')]
	public function testIsolationEnvironmentAnnotated()
	{
		$this->assertEquals('testing.testIsolationAnnotated', AgaviConfig::get('testing.environment'));
	}
	
	public function testIsolationDefaultContext()
	{
		$this->assertEquals('web-isolated', AgaviConfig::get('core.default_context'));
	}
	
	/**
	 * Test method with method-level isolation default context attribute
	 */
	#[AgaviIsolationDefaultContext('web-isolated-annotated-method')]
	public function testIsolationDefaultContextAnnotated()
	{
		$this->assertEquals('web-isolated-annotated-method', AgaviConfig::get('core.default_context'));
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