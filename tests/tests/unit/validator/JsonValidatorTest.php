<?php

use Quiote\Validator\JsonValidator;
use Quiote\Validator\Validator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class JsonValidatorTest extends BaseValidatorTest
{
	public function testExecute(): void
	{
		$this->doTestExecute(JsonValidator::class, json_encode(['foo' => 'bar']), Validator::SUCCESS);
		
		$errors = [
			'syntax' => $errorMsg = 'Syntax error',
		];
		$this->doTestExecute(JsonValidator::class, '{', Validator::ERROR, $errorMsg, $errors);
	}

	public function testExport(): void
	{
		$value = ['foo' => 'bar'];

		$res = $this->executeValidator(JsonValidator::class, json_encode($value), [], [
			'export' => 'test',
		]);
		$this->assertEquals($res['rd']->getParameter('test'), $value);

		$res = $this->executeValidator(JsonValidator::class, json_encode($value), [], [
			'export' => 'test',
			'assoc'  => false,
		]);
		$this->assertEquals($res['rd']->getParameter('test'), (object)$value);
	}
}

?>