<?php

use Quiote\Exception\ConfigurationException;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

class InarrayValidatorTest extends UnitTestCase
{
	/**
	 * @var ValidationManager
	 */
	protected $vm;

	#[\Override]
    public function setUp(): void
	{
		$this->vm = $this->getContext()->createInstanceFor('validation_manager');
	}

	public function testAcceptsValueInList(): void
	{
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => ['red', 'green', 'blue']]);
		$rd = $this->newWebRequest(['choice' => 'GREEN']);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded.');
	}

	public function testRejectsValueNotInList(): void
	{
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => ['red', 'green', 'blue']]);
		$rd = $this->newWebRequest(['choice' => 'purple']);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::ERROR, $result, 'Failed asserting that the validation failed.');
	}

	/**
	 * Case-insensitive comparison used to crash with a TypeError as soon as
	 * either the input value or an allowlist entry was a non-string scalar
	 * (int/bool/float), since strtolower() was called directly on it without
	 * a string cast. Verifies non-string scalars are handled correctly
	 * instead of blowing up.
	 */
	public function testAcceptsNonStringScalarValueAgainstNonStringScalarList(): void
	{
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => [1, 2, 3]]);
		$rd = $this->newWebRequest(['choice' => 2]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded for a non-string scalar value.');
	}

	public function testRejectsNonStringScalarValueNotInList(): void
	{
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => [1, 2, 3]]);
		$rd = $this->newWebRequest(['choice' => 4]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::ERROR, $result, 'Failed asserting that the validation failed for a non-string scalar value.');
	}

	public function testSplitsValuesFromDelimitedString(): void
	{
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => 'red,green,blue', 'sep' => ',']);
		$rd = $this->newWebRequest(['choice' => 'green']);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that a delimited "values" string is split and matched.');
	}

	/**
	 * "values" must be an array or a delimitable string. A non-array,
	 * non-string value (e.g. an int) is a validator misconfiguration, not
	 * a validation-time input problem, so it must fail loudly.
	 */
	public function testRejectsNonArrayNonStringValuesParameter(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => 42]);
		$rd = $this->newWebRequest(['choice' => 'red']);
		$validator->execute($rd);
	}

	public function testRejectsNonStringSepParameter(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => 'red,green', 'sep' => 5]);
		$rd = $this->newWebRequest(['choice' => 'red']);
		$validator->execute($rd);
	}

	public function testRejectsNonBooleanStrictParameter(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(InarrayValidator::class, ['choice'], ['' => 'invalid choice'], ['values' => ['red', 'green'], 'strict' => 'yes']);
		$rd = $this->newWebRequest(['choice' => 'red']);
		$validator->execute($rd);
	}
}

?>
