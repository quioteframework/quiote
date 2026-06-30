<?php

use Agavi\Validator\AgaviStringValidator;
use Agavi\Validator\AgaviValidator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class AgaviStringValidatorTest extends BaseValidatorTest
{
	public function testExecute()
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
		$error = '';
		foreach ($good as &$value) {
			$this->doTestExecute(AgaviStringValidator::class, $value, AgaviValidator::SUCCESS);
		}
	}

	public function testExecuteMax()
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
			$this->doTestExecute(AgaviStringValidator::class, $value, AgaviValidator::SUCCESS, null, $errors, $parameters);
		}
		foreach ($bad as &$value) {
			$this->doTestExecute(AgaviStringValidator::class, $value, AgaviValidator::ERROR, $errorMsg, $errors, $parameters);
		}
	}

	public function testExecuteMin()
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
			$this->doTestExecute(AgaviStringValidator::class, $value, AgaviValidator::SUCCESS, null, $errors, $parameters);
		}
		foreach ($bad as &$value) {
			$this->doTestExecute(AgaviStringValidator::class, $value, AgaviValidator::ERROR, $errorMsg, $errors, $parameters);
		}
	}
}

?>