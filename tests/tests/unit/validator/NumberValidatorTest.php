<?php

use Quiote\Exception\ConfigurationException;
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
		$tm = $ctx->getContainer()->tryGet(\Quiote\Translation\TranslationManager::class);
		if($tm === null) {
			$tm = $this->installTestTranslationManager();
			$tm->startup();
		}
		$this->vm = $ctx->getContainer()->get(\Quiote\Validator\ValidationManager::class);
	}

	public function testMinAndMaxAndTypeAreAcceptedParameters(): void
	{
		$accepted = NumberValidator::getAcceptedParameters();

		foreach (['no_locale', 'in_locale', 'type', 'cast_to', 'min', 'max'] as $name) {
			$this->assertContains($name, $accepted);
		}
	}

	/**
	 * count() and arithmetic on a non-scalar would raise notices before ever
	 * reaching a verdict, so an array or object submitted where a number was
	 * expected is rejected up front.
	 */
	public function testANonScalarValueIsRejectedRatherThanParsed(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'not a number'], []);

		$this->assertSame(Validator::ERROR, $validator->execute($this->newWebRequest(['number' => ['1', '2']])));
	}

	/**
	 * A value already of a numeric type skips parsing entirely: there is no
	 * string to interpret, and running it through the locale formatter could
	 * only lose precision.
	 */
	public function testAnAlreadyNumericValueBypassesLocaleParsing(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid'], ['type' => 'float']);
		$request = $this->newWebRequest(['number' => 1.5]);

		$this->assertSame(Validator::SUCCESS, $validator->execute($request));

		$mutated = $validator->getMutatedRequest() ?? $request;
		$this->assertSame(1.5, $mutated->getParameter('number'));
	}

	public function testAnAlreadyIntegerValuePassesAnIntegerTypeCheck(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid'], ['type' => 'int']);

		$this->assertSame(Validator::SUCCESS, $validator->execute($this->newWebRequest(['number' => 42])));
	}

	/**
	 * "double" is an accepted spelling of the float type, since that is what
	 * PHP's own gettype() calls it.
	 */
	public function testDoubleIsAcceptedAsASpellingOfFloat(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid'], ['type' => 'double']);

		$this->assertSame(Validator::SUCCESS, $validator->execute($this->newWebRequest(['number' => '1.25'])));
	}

	public function testDoubleIsAlsoAcceptedAsACastTarget(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid'], ['cast_to' => 'double']);
		$request = $this->newWebRequest(['number' => '7']);

		$this->assertSame(Validator::SUCCESS, $validator->execute($request));

		$mutated = $validator->getMutatedRequest() ?? $request;
		$this->assertSame(7.0, $mutated->getParameter('number'));
	}

	/**
	 * With no type configured, anything the formatter could parse is a number
	 * -- but trailing junk it had to ignore is not.
	 */
	public function testWithoutATypeAnUnparseableValueIsRejected(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid'], []);

		$this->assertSame(Validator::ERROR, $validator->execute($this->newWebRequest(['number' => 'not a number at all'])));
	}

	public function testWithoutATypeTrailingCharactersAreRejected(): void
	{
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], ['' => 'invalid'], []);

		$this->assertSame(Validator::ERROR, $validator->execute($this->newWebRequest(['number' => '12abc'])));
	}

	/**
	 * Locale-aware parsing is what makes "1,5" a number in a locale that
	 * writes decimals that way; no_locale turns that off for a field that
	 * carries a machine-formatted value.
	 */
	public function testNoLocaleParsesTheValueWithoutTheCurrentLocale(): void
	{
		$validator = $this->vm->createValidator(
			NumberValidator::class,
			['number'],
			['' => 'invalid'],
			['no_locale' => true, 'type' => 'float'],
		);

		$this->assertSame(Validator::SUCCESS, $validator->execute($this->newWebRequest(['number' => '1.5'])));
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

	/**
	 * "type" is a validator configuration value, not user input, so a
	 * non-string value is a misconfiguration and must fail loudly.
	 */
	public function testNonStringTypeParameterThrows(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], [], ['type' => ['int']]);
		$rd = $this->newWebRequest(['number' => '1']);
		$validator->execute($rd);
	}

	/**
	 * "cast_to" is a validator configuration value; a non-string value is a
	 * misconfiguration.
	 */
	public function testNonStringCastToParameterThrows(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(NumberValidator::class, ['number'], [], ['type' => 'int', 'cast_to' => 123]);
		$rd = $this->newWebRequest(['number' => '1']);
		$validator->execute($rd);
	}

}

?>
