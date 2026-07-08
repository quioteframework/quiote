<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationArgument;
use Quiote\Validator\ValidationReport;
use Quiote\Validator\Validator;

class ValidationReportTest extends UnitTestCase
{
	private ValidationReport $_report;

	#[\Override]
    public function setUp(): void
	{
		$this->_report = new ValidationReport();
	}
	
	public function testDependTokensInitiallyEmpty(): void
	{
		$this->assertEquals([], $this->_report->getDependTokens());
	}
	
	public function testSetGetDependTokens(): void
	{
		$tokens = ['token1' => true, 'token2' => true];
		$this->_report->setDependTokens($tokens);
		$this->assertEquals($tokens, $this->_report->getDependTokens());
	}
	
	public function testHasDependToken(): void
	{
		$tokens = ['token1' => true, 'token2' => true];
		$this->_report->setDependTokens($tokens);
		$this->assertTrue($this->_report->hasDependToken('token1'));
		$this->assertTrue($this->_report->hasDependToken('token2'));
		$this->assertFalse($this->_report->hasDependToken('token3'));
	}

	/**
	 * Regression test: ValidationReportQuery::getResult() used to crash with a
	 * TypeError (count()/reset() on null) when called on a query without a
	 * byArgument() filter and without any incidents recorded (i.e. it had to
	 * fall through to scanning ValidationReport::getArgumentResults()
	 * directly). Guard that this now returns the correct severity instead.
	 */
	public function testGetResultWithoutArgumentFilterFallsBackToArgumentResults(): void
	{
		$argument = new ValidationArgument('field1');
		$this->_report->addArgumentResult($argument, Validator::ERROR);

		$this->assertSame(Validator::ERROR, $this->_report->createQuery()->getResult());
	}

	/**
	 * Same fallback path, but additionally filtered byValidator() (no
	 * argument filter) -- also used to hit the null count()/reset() crash.
	 */
	public function testGetResultWithoutArgumentFilterButWithValidatorFilter(): void
	{
		$argument = new ValidationArgument('field1');
		$this->_report->addArgumentResult($argument, Validator::ERROR, null);

		// No matching validator name recorded -> no results for the filter -> null.
		$this->assertNull($this->_report->createQuery()->byValidator('some_validator')->getResult());
	}

}
?>