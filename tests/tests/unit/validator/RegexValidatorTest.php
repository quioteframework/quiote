<?php

use Quiote\Validator\RegexValidator;
use Quiote\Validator\Validator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class RegexValidatorTest extends BaseValidatorTest
{

	public function testExecute()
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
}

?>