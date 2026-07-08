<?php

use Quiote\Validator\StringValidator;
use Quiote\Validator\Validator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class StringValidatorTest extends BaseValidatorTest
{
	public function testExecute(): void
	{
		$good = [
			'1',
			'1.0',
			'2222222222',
			'-111111',
			'-0.54',
			'1_5',
			'BOB',
			'1.5B',
			'%%!@#$%#'
		];
		foreach ($good as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::SUCCESS);
		}
	}

	public function testExecuteMax(): void
	{
		$bad = [
			'12345',
			'bbbbbbbb',
			'12bb34bb56bb  z',
			'      '
		];
		$good = [
			'3',
			'3.99',
			'    '
		];
		$parameters = [
			'max' => 4,
		];
		$errors = [
			'max' => $errorMsg = 'Some other error',
		];
		foreach ($good as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::SUCCESS, null, $errors, $parameters);
		}
		foreach ($bad as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::ERROR, $errorMsg, $errors, $parameters);
		}
	}

	public function testExecuteMin(): void
	{
		$bad = [
			'5',
			'4.',
			'  '
		];
		$good = [
			'333',
			'3.9',
			'     '
		];
		$parameters = [
			'min' => 3,
		];
		$errors = [
			'min' => $errorMsg = 'Some other error',
		];
		foreach ($good as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::SUCCESS, null, $errors, $parameters);
		}
		foreach ($bad as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::ERROR, $errorMsg, $errors, $parameters);
		}
	}

	/**
	 * Non-string scalar inputs (bool/int/float) used to reach preg_match()/
	 * strlen() without a string cast, throwing a TypeError instead of
	 * validating normally. Verifies they're coerced to string and validated
	 * like any other value, both when they pass and when they fail a length
	 * constraint.
	 */
	public function testExecuteWithNonStringScalarValue(): void
	{
		$this->doTestExecute(StringValidator::class, 12345, Validator::SUCCESS, null, [], ['min' => 3]);
		$this->doTestExecute(StringValidator::class, true, Validator::ERROR, 'too short', ['min' => 'too short'], ['min' => 3]);
	}
}

?>
