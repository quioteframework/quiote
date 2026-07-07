<?php

use Quiote\Validator\EmailValidator;
use Quiote\Validator\Validator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

/**
 * A malformed email must not just fail (SUCCESS/ERROR result) but also record
 * an incident with an error message -- previously the format-failure branch
 * returned false directly without calling throwError() first, so the failure
 * was silently missing from getErrorMessages()/getReport()->getErrors().
 */
class EmailValidatorReportTest extends BaseValidatorTest
{
	public function testExecuteRecordsErrorMessageOnMalformedEmail()
	{
		$errors = [
			'' => $errorMsg = 'Not a valid email address',
		];
		$this->doTestExecute(EmailValidator::class, 'sjklsdfsfd', Validator::ERROR, $errorMsg, $errors);
	}

	public function testExecuteRecordsNoErrorMessageOnValidEmail()
	{
		$this->doTestExecute(EmailValidator::class, 'bob@quiote.org', Validator::SUCCESS);
	}
}

?>
