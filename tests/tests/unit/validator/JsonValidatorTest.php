<?php

use Quiote\Exception\ConfigurationException;
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

	/**
	 * A non-scalar input value (e.g. an array) must fail validation instead
	 * of blowing up when cast to string.
	 */
	public function testNonScalarValueFailsValidation(): void
	{
		$this->doTestExecute(JsonValidator::class, ['not', 'a', 'string'], Validator::ERROR, '');
	}

	/**
	 * "assoc" is a validator configuration flag, not user input, so a
	 * non-boolean value is a misconfiguration and must fail loudly.
	 */
	public function testNonBooleanAssocParameterThrows(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->executeValidator(JsonValidator::class, json_encode(['foo' => 'bar']), [], ['assoc' => 'yes']);
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

	/**
	 * Without an explicit 'export' parameter, the decoded value must still
	 * replace the raw JSON string under the validator's own argument name --
	 * a caller reading the argument back afterwards should never see the
	 * undecoded string.
	 */
	public function testExportsDecodedValueByDefaultUnderOwnArgumentName(): void
	{
		$value = ['foo' => 'bar'];

		$res = $this->executeValidator(JsonValidator::class, json_encode($value), [], []);
		$this->assertSame(Validator::SUCCESS, $res['result']);
		$this->assertEquals($value, $res['rd']->getParameter('value'));
	}
}

?>