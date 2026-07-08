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

	public function testIsolationEnvironment(): void
	{
		$this->assertEquals('testing.testIsolation', Config::getString('testing.environment'));
	}

	/**
	 * Test method with method-level isolation environment attribute
	 */
	#[IsolationEnvironment('testing.testIsolationAnnotated')]
	public function testIsolationEnvironmentAnnotated(): void
	{
		$this->assertEquals('testing.testIsolationAnnotated', Config::getString('testing.environment'));
	}

	public function testIsolationDefaultContext(): void
	{
		$this->assertEquals('web-isolated', Config::getNullableString('core.default_context'));
	}

	/**
	 * Test method with method-level isolation default context attribute
	 */
	#[IsolationDefaultContext('web-isolated-annotated-method')]
	public function testIsolationDefaultContextAnnotated(): void
	{
		$this->assertEquals('web-isolated-annotated-method', Config::getNullableString('core.default_context'));
	}

	/**
	 * @preserveGlobalState enabled
	 */
	public function testPreserveGlobalStateOnWorks(): void {
		// this test just needs to run to signal success; the annotation exercises
		// PHPUnit's process-isolation state handling rather than an assertable value.
		$this->addToAssertionCount(1);
	}

	/**
	 * @preserveGlobalState disabled
	 */
	public function testPreserveGlobalStateOffWorks(): void {
		// this test just needs to run to signal success; the annotation exercises
		// PHPUnit's process-isolation state handling rather than an assertable value.
		$this->addToAssertionCount(1);
	}

}

?>
