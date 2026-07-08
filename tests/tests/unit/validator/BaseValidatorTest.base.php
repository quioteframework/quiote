<?php

use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

// Base class for validator tests (renamed *.base.php to avoid direct PHPUnit discovery)
class BaseValidatorTest extends UnitTestCase
{
	/**
	 * @param class-string<Validator> $class
	 * @param array<string,string> $errors
	 * @param array<string,mixed> $parameters
	 * @return array{result: int, vm: ValidationManager, rd: WebRequest}
	 */
	protected function executeValidator(string $class, mixed $value, array $errors = [], array $parameters = []): array
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$validator = $vm->createValidator($class, ['value'], $errors, $parameters);
		$rd = $this->newWebRequest(['value' => $value]);
		$result = $validator->execute($rd);
		// WebRequest is immutable: export()/casting inside execute() replaced the
		// validator's own copy rather than mutating $rd in place.
		$rd = $validator->getMutatedRequest() ?? $rd;

		return [
			'result' => $result,
			'vm' => $vm,
			'rd' => $rd
		];
	}

	/**
	 * @param class-string<Validator> $class
	 * @param array<string,string> $errors
	 * @param array<string,mixed> $parameters
	 */
	protected function doTestExecute(string $class, mixed $value, int $expectedResult, ?string $expectedError = null, array $errors = [], array $parameters = []): void
	{
		$res = $this->executeValidator($class, $value, $errors, $parameters);
		$this->assertSame($expectedResult, $res['result']);
		$errorMessages = $res['vm']->getReport()->getErrorMessages();
		if($expectedError === null) {
			$this->assertCount(0, $errorMessages);
		} else {
			$this->assertCount(1, $errorMessages);
			$this->assertSame($expectedError, reset($errorMessages));
		}
	}

	public function testBaseValidatorDummy(): void
	{
		$this->addToAssertionCount(1);
	}
}

?>
