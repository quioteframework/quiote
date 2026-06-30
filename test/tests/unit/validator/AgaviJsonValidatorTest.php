<?php

use Agavi\Validator\AgaviJsonValidator;
use Agavi\Validator\AgaviValidator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class AgaviJsonValidatorTest extends BaseValidatorTest
{
	public function testExecute()
	{
		$this->doTestExecute(AgaviJsonValidator::class, json_encode(['foo' => 'bar']), AgaviValidator::SUCCESS);
		
		$errors = [
			'syntax' => $errorMsg = 'Syntax error',
		];
		$this->doTestExecute(AgaviJsonValidator::class, '{', AgaviValidator::ERROR, $errorMsg, $errors);
	}

	public function testExport()
	{
		$value = ['foo' => 'bar'];

		$res = $this->executeValidator(AgaviJsonValidator::class, json_encode($value), [], [
			'export' => 'test',
		]);
		$this->assertEquals($res['rd']->getParameter('test'), $value);

		$res = $this->executeValidator(AgaviJsonValidator::class, json_encode($value), [], [
			'export' => 'test',
			'assoc'  => false,
		]);
		$this->assertEquals($res['rd']->getParameter('test'), (object)$value);
	}
}

?>