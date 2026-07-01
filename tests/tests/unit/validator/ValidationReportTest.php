<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationReport;

class ValidationReportTest extends UnitTestCase
{
	private $_context = null;
	private $_report = null;
	
	#[\Override]
    public function setUp(): void
	{
		$this->_context = $this->getContext();
		$this->_report = new ValidationReport();
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->_context = null;
	}
	
	public function testDependTokensInitiallyEmpty()
	{
		$this->assertEquals([], $this->_report->getDependTokens());
	}
	
	public function testSetGetDependTokens()
	{
		$tokens = ['token1' => true, 'token2' => true];
		$this->_report->setDependTokens($tokens);
		$this->assertEquals($tokens, $this->_report->getDependTokens());
	}
	
	public function testHasDependToken()
	{
		$tokens = ['token1' => true, 'token2' => true];
		$this->_report->setDependTokens($tokens);
		$this->assertTrue($this->_report->hasDependToken('token1'));
		$this->assertTrue($this->_report->hasDependToken('token2'));
		$this->assertFalse($this->_report->hasDependToken('token3'));
	}
	
}
?>