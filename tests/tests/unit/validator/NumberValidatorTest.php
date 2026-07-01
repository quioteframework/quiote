<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\Validator;

class NumberValidatorTest extends UnitTestCase
{

	/**
	 * @var ValidationManager
	 */
	protected $vm;

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
	
	public function testNoCastOnFail()
	{
		$number = '1.23';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['invalid argument'], $parameters = ['type' => 'int']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::ERROR, $result);
		$this->assertEquals($number, $rd->getParameter('number'));
		$this->assertTrue(is_string($rd->getParameter('number')), 'Failed asserting that the parameter "number" is a string');
	}
	
	public function testImplicitCastToFloat()
	{
		$number = '1.23';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['invalid argument'], $parameters = ['type' => 'float']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals($number, $rd->getParameter('number'));
		$this->assertTrue(is_float($rd->getParameter('number')), 'Failed asserting that the parameter "number" is a float');
	}
	
	public function testImplicitCastToInt()
	{
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['invalid argument'], $parameters = ['type' => 'int']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals($number, $rd->getParameter('number'));
		$this->assertTrue(is_int($rd->getParameter('number')), 'Failed asserting that the parameter "number" is an int');
	}
	
	public function testExplicitCastToInt()
	{
		$number = '1.23';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['invalid argument'], $parameters = ['type' => 'float', 'cast_to' => 'int']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(1, $rd->getParameter('number'));
		$this->assertTrue(is_int($rd->getParameter('number')), 'Failed asserting that the parameter "number" is an int');
	}
	
	public function testExplicitCastToFloat()
	{
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['invalid argument'], $parameters = ['type' => 'float', 'cast_to' => 'float']);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(1, $rd->getParameter('number'));
		$this->assertTrue(is_float($rd->getParameter('number')), 'Failed asserting that the parameter "number" is a float');
	}
	
	public function testMinFail()
	{
		$minError = 'value too low';
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => $minError], $parameters = ['type' => 'int', 'min' => 2]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::ERROR, $result);
		$this->assertEquals(1, $this->vm->getReport()->byErrorName('min')->count(), 'Failes asserting that there is one min error.');
		$this->assertEquals([$minError], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that the min error message is emittet.');
	}

	public function testGetErrorMessagesWithFieldsAnnotatesTheField()
	{
		$minError = 'value too low';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => $minError], $parameters = ['type' => 'int', 'min' => 2]);
		$rd = $this->newWebRequest(['number' => '1']);
		$this->assertEquals(Validator::ERROR, $validator->execute($rd));

		// getErrorMessagesWithFields() must return the field-annotated structure
		// (the same shape the deprecated ValidationManager::getErrorMessages()
		// produced), while getErrorMessages() stays a flat list of strings.
		$this->assertEquals([$minError], $this->vm->getReport()->getErrorMessages());
		$this->assertEquals(
			[['message' => $minError, 'errors' => ['number']]],
			$this->vm->getReport()->getErrorMessagesWithFields()
		);
	}

	public function testGetErrorMessagesWithFieldsEmptyOnSuccess()
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => 'value too low'], $parameters = ['type' => 'int', 'min' => 1]);
		$rd = $this->newWebRequest(['number' => '1']);
		$this->assertEquals(Validator::SUCCESS, $validator->execute($rd));
		$this->assertEquals([], $this->vm->getReport()->getErrorMessagesWithFields());
	}

	public function testMinSuccess()
	{
		$minError = 'value too low';
		$number = '1';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['min' => $minError], $parameters = ['type' => 'int', 'min' => 1]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(0, $this->vm->getReport()->byErrorName('min')->count(), 'Failes asserting that there is no min error.');
		$this->assertEquals([], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that no min error message is emittet.');
	}
	
	public function testMaxFail()
	{
		$maxError = 'value too high';
		$number = '2';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['max' => $maxError], $parameters = ['type' => 'int', 'max' => 1]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::ERROR, $result);
		$this->assertEquals(1, $this->vm->getReport()->byErrorName('max')->count(), 'Failes asserting that there is one max error.');
		$this->assertEquals([$maxError], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that the max error message is emittet.');
	}
	
	public function testMaxSuccess()
	{
		$maxError = 'value too high';
		$number = '2';
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['max' => $maxError], $parameters = ['type' => 'int', 'max' => 2]);
		$rd = $this->newWebRequest(['number' => $number]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result);
		$this->assertEquals(0, $this->vm->getReport()->byErrorName('max')->count(), 'Failes asserting that there is no max error.');
		$this->assertEquals([], $this->vm->getReport()->getErrorMessages(), 'Failed asserting that no max error message is emittet.');
	}
	
}

?>