<?php

use Quiote\Validator\StringValidator;
use Quiote\Validator\Validator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class StringValidatorTestMarkupStripper extends StringValidator
{
	protected function normalize(string $value): string
	{
		return strip_tags($value);
	}
}

class StringValidatorTest extends BaseValidatorTest
{
	public function testExecute(): void
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
		foreach ($good as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::SUCCESS);
		}
	}

	public function testExecuteMax(): void
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
			$this->doTestExecute(StringValidator::class, $value, Validator::SUCCESS, null, $errors, $parameters);
		}
		foreach ($bad as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::ERROR, $errorMsg, $errors, $parameters);
		}
	}

	public function testExecuteMin(): void
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
			$this->doTestExecute(StringValidator::class, $value, Validator::SUCCESS, null, $errors, $parameters);
		}
		foreach ($bad as &$value) {
			$this->doTestExecute(StringValidator::class, $value, Validator::ERROR, $errorMsg, $errors, $parameters);
		}
	}

	/**
	 * Non-string scalar inputs (bool/int/float) used to reach preg_match()/
	 * strlen() without a string cast, throwing a TypeError instead of
	 * validating normally. Verifies they're coerced to string and validated
	 * like any other value, both when they pass and when they fail a length
	 * constraint.
	 */
	public function testExecuteWithNonStringScalarValue(): void
	{
		$this->doTestExecute(StringValidator::class, 12345, Validator::SUCCESS, null, [], ['min' => 3]);
		$this->doTestExecute(StringValidator::class, true, Validator::ERROR, 'too short', ['min' => 'too short'], ['min' => 3]);
	}

	/**
	 * Without an explicit 'export' parameter, the string-cast value must still
	 * replace the raw input under the validator's own argument name -- a
	 * caller reading the argument back afterwards should never see the
	 * original non-string scalar.
	 */
	public function testExportsSanitizedValueByDefaultUnderOwnArgumentName(): void
	{
		$res = $this->executeValidator(StringValidator::class, 123, [], []);
		$this->assertSame(Validator::SUCCESS, $res['result']);
		$this->assertSame('123', $res['rd']->getParameter('value'));
	}

	/**
	 * An explicit 'export' parameter still wins over the default own-name
	 * target.
	 */
	public function testExplicitExportTargetOverridesDefault(): void
	{
		$res = $this->executeValidator(StringValidator::class, 123, [], ['export' => 'other']);
		$this->assertSame(Validator::SUCCESS, $res['result']);
		$this->assertSame('123', $res['rd']->getParameter('other'));
	}

	/**
	 * normalize() rewrites the trimmed string before the length checks, and
	 * its result is what the action reads back.
	 */
	public function testNormalizedValueIsWrittenBack(): void
	{
		$res = $this->executeValidator(StringValidatorTestMarkupStripper::class, ' <b>bold</b> ', [], ['trim' => true]);
		$this->assertSame(Validator::SUCCESS, $res['result']);
		$this->assertSame('bold', $res['rd']->getParameter('value'));
	}

	public function testLengthChecksApplyToTheNormalizedValue(): void
	{
		$this->doTestExecute(StringValidatorTestMarkupStripper::class, '<i></i>a', Validator::ERROR, 'too short', ['min' => 'too short'], ['min' => 2]);
		$this->doTestExecute(StringValidatorTestMarkupStripper::class, '<span class="long">ab</span>', Validator::SUCCESS, null, [], ['max' => 2]);
	}

	public function testDefaultNormalizeLeavesTheStringAlone(): void
	{
		$res = $this->executeValidator(StringValidator::class, '<b>bold</b>', [], []);
		$this->assertSame('<b>bold</b>', $res['rd']->getParameter('value'));
	}
}

?>
