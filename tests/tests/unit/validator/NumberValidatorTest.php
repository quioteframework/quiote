<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

class NumberValidatorTest extends UnitTestCase
{

	protected ValidationManager $vm;

	#[\Override]
    public function setUp(): void
	{
		$ctx = $this->getContext();
		// Ensure translation manager is initialized so numeric formatting side paths don't fail later.
		$tm = $ctx->getTranslationManager();
		if($tm === null) {
			$info = $ctx->getFactoryInfo('translation_manager');
			if ($info === null || empty($info['class'])) {
				$ctx->setFactoryInfo('translation_manager', [
					'class' => \Quiote\Translation\TranslationManager::class,
					'parameters' => [],
				]);
			}
			$tm = $ctx->createInstanceFor('translation_manager');
			$ro = new \ReflectionObject($ctx);
			$prop = $ro->getProperty('translationManager');

			$prop->setValue($ctx, $tm);
			$seqProp = $ro->getProperty('shutdownSequence');

			$seq = $seqProp->getValue($ctx);
			if(!in_array($tm, $seq, true)) { $seq[] = $tm; $seqProp->setValue($ctx, $seq); }
			$tm->startup();
		}
		$this->vm = $ctx->createInstanceFor('validation_manager');
	}

	public function testNoCastOnFail(): void
	{
		$number = '1.23';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid argument'], $parameters = ['type' => 'int']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::ERROR, $result);
		$this->assertEquals($number, $rd->getParameter('number'));
		$this->assertTrue(is_string($rd->getParameter('number')), 'Failed asserting that the parameter "number" is a string');
	}

	public function testImplicitCastToFloat(): void
	{
		$number = '1.23';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid argument'], $parameters = ['type' => 'float']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals($number, $rd->getParameter('number'));
		$this->assertTrue(is_float($rd->getParameter('number')), 'Failed asserting that the parameter "number" is a float');
	}

	public function testImplicitCastToInt(): void
	{
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid argument'], $parameters = ['type' => 'int']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals($number, $rd->getParameter('number'));
		$this->assertTrue(is_int($rd->getParameter('number')), 'Failed asserting that the parameter "number" is an int');
	}

	public function testExplicitCastToInt(): void
	{
		$number = '1.23';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid argument'], $parameters = ['type' => 'float', 'cast_to' => 'int']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(1, $rd->getParameter('number'));
		$this->assertTrue(is_int($rd->getParameter('number')), 'Failed asserting that the parameter "number" is an int');
	}

	public function testExplicitCastToFloat(): void
	{
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid argument'], $parameters = ['type' => 'float', 'cast_to' => 'float']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(1, $rd->getParameter('number'));
		$this->assertTrue(is_float($rd->getParameter('number')), 'Failed asserting that the parameter "number" is a float');
	}

	public function testMinFail(): void
	{
		$minError = 'value too low';
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => $minError], $parameters = ['type' => 'int', 'min' => 2]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::ERROR, $result);
		$this->assertEquals(1, $this->vm->getReport()->byErrorName('min')->count(), 'Failes asserting that there is one min error.');
		$this->assertEquals([$minError], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that the min error message is emittet.');
	}

	public function testGetErrorMessagesWithFieldsAnnotatesTheField(): void
	{
		$minError = 'value too low';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => $minError], $parameters = ['type' => 'int', 'min' => 2]);
		$rd = $this->newWebRequest(['number' => '1']);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::ERROR, $result);

		// getErrorMessagesWithFields() must return the field-annotated structure
		// (the same shape the deprecated ValidationManager::getErrorMessages()
		// produced), while getErrorMessages() stays a flat list of strings.
		$this->assertEquals([$minError], $this->vm->getReport()->getErrorMessages());
		$this->assertEquals(
			[['message' => $minError, 'errors' => ['number']]],
			$this->vm->getReport()->getErrorMessagesWithFields()
		);
	}

	public function testGetErrorMessagesWithFieldsEmptyOnSuccess(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => 'value too low'], $parameters = ['type' => 'int', 'min' => 1]);
		$rd = $this->newWebRequest(['number' => '1']);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals([], $this->vm->getReport()->getErrorMessagesWithFields());
	}

	public function testMinSuccess(): void
	{
		$minError = 'value too low';
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => $minError], $parameters = ['type' => 'int', 'min' => 1]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(0, $this->vm->getReport()->byErrorName('min')->count(), 'Failes asserting that there is no min error.');
		$this->assertEquals([], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that no min error message is emittet.');
	}

	public function testMaxFail(): void
	{
		$maxError = 'value too high';
		$number = '2';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['max' => $maxError], $parameters = ['type' => 'int', 'max' => 1]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::ERROR, $result);
		$this->assertEquals(1, $this->vm->getReport()->byErrorName('max')->count(), 'Failes asserting that there is one max error.');
		$this->assertEquals([$maxError], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that the max error message is emittet.');
	}

	public function testMaxSuccess(): void
	{
		$maxError = 'value too high';
		$number = '2';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['max' => $maxError], $parameters = ['type' => 'int', 'max' => 2]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(0, $this->vm->getReport()->byErrorName('max')->count(), 'Failes asserting that there is no max error.');
		$this->assertEquals([], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that no max error message is emittet.');
	}

	/**
	 * A boolean input used to reach DecimalFormatter::parse() without a
	 * string cast, which threw a TypeError instead of failing validation
	 * gracefully. Verifies a non-string scalar is rejected as an ordinary
	 * validation failure.
	 */
	public function testBooleanValueFailsGracefullyInsteadOfCrashing(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid argument'], $parameters = ['type' => 'int']);
		$rd = $this->newWebRequest(['number' => false]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::ERROR, $result);
	}

}

?>
