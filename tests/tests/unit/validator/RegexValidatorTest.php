<?php

use Quiote\Validator\RegexValidator;
use Quiote\Validator\Validator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class RegexValidatorTest extends BaseValidatorTest
{

	public function testExecute(): void
	{
		$good = [
			'nnbb',
			'nbb',
			'nnnbb'
		];
		$bad = [
			'bb',
			'nnnnbb',
			'jdsakl'
		];
		$parameters = ['pattern' => '/^[n]{1,3}bb$/', 'match' => true];
		$errors = ['' => $errorMsg = 'Some other error'];
		foreach($good as $value) {
			$this->doTestExecute(RegexValidator::class, $value, Validator::SUCCESS, null, $errors, $parameters);
		}
		foreach($bad as $value) {
			$this->doTestExecute(RegexValidator::class, $value, Validator::ERROR, $errorMsg, $errors, $parameters);
		}

		$parameters['match'] = false;
		foreach($bad as $value) {
			$this->doTestExecute(RegexValidator::class, $value, Validator::SUCCESS, null, $errors, $parameters);
		}
		foreach($good as $value) {
			$this->doTestExecute(RegexValidator::class, $value, Validator::ERROR, $errorMsg, $errors, $parameters);
		}
	}

	/**
	 * A non-string scalar input (int/bool) used to reach preg_match()
	 * without a string cast, throwing a TypeError instead of failing
	 * validation gracefully. Verifies it's coerced to string and matched
	 * like any other value.
	 */
	public function testExecuteWithNonStringScalarValue(): void
	{
		$parameters = ['pattern' => '/^[0-9]+$/', 'match' => true];
		$errors = ['' => $errorMsg = 'Some other error'];
		$this->doTestExecute(RegexValidator::class, 12345, Validator::SUCCESS, null, $errors, $parameters);
		$this->doTestExecute(RegexValidator::class, false, Validator::ERROR, $errorMsg, $errors, $parameters);
	}
}

?>
