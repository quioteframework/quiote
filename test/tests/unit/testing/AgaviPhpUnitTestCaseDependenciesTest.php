<?php

use Agavi\Testing\AgaviPhpUnitTestCase;

require_once __DIR__ . '/../../../lib/testing/SandboxTestingChildClass.class.php';

class AgaviPhpUnitTestCaseDependenciesTestDummy extends SandboxTestingChildClass {} 

/**
 * @runTestsInSeparateProcesses
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class AgaviPhpUnitTestCaseDependenciesTest extends AgaviPhpUnitTestCase
{
	/*
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(true)]
	public function testDependenciesAreLoadedWithGlobalState()
	{
		// this test is successful as soon as the test runs.
		// It would fail way before if any of the dependencies 
		// from SandboxTestingChildClass didn't load
		$this->assertTrue(true);
	}
	
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function testDependenciesAreLoadedWithoutGlobalState()
	{
		// this test is successful as soon as the test runs.
		// It would fail way before if any of the dependencies 
		// from SandboxTestingChildClass didn't load
		$this->assertTrue(true);
	}
	*/
}

?>