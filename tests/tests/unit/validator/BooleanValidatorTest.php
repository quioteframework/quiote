<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanValidatorTest extends UnitTestCase
{

	protected ValidationManager $vm;

	#[\Override]
    public function setUp(): void
	{
		$this->vm = $this->getContext()->createInstanceFor('validation_manager');
	}

	#[DataProvider('validValues')]
	public function testAccept(mixed $value, bool $expectedResult): void
	{
		$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['' => 'invalid argument'], []);
		$rd = $this->newWebRequest(['bool' => $value]);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded.');
		$this->assertEquals($expectedResult, $rd->getParameter('bool'), 'Failed asserting that the validated value is the expected value');
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function validValues(): array {

		return [
			'yes' => ['yes', true],
			'no' => ['no', false],
			'true' => ['true', true],
			'false' => ['false', false],
			'on' => ['on', true],
			'off' => ['off', false],
			'(bool)true' => [true, true],
			'(bool)false' => [false, false],
			'(int)1' => [1, true],
			'(int)0' => [0, false],
			'(string)1' => ['1', true],
			'(string)0' => ['0', false]
		];

	}

	#[DataProvider('invalidValues')]
	public function testNotAccept(mixed $value): void
	{
		$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['' => 'invalid argument'], ['export' => 'exported']);
		// Pre-whitelist export target so reading it after failed validation returns null instead of throwing.
		$rd = $this->newWebRequest(['bool' => $value], ['exported']);
		$result = $validator->execute($rd);
		$rd = $validator->getMutatedRequest() ?? $rd;
		$this->assertEquals(Validator::ERROR, $result, 'Failed asserting that the validation failed.');
		$this->assertNull($rd->getParameter('exported'), 'Failed asserting that the value is not exported');
		$this->assertEquals($value, $rd->getParameter('bool'), 'Failed asserting that the validated value is the original value');
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidValues(): array {
		return [
			'nä' => ['nä'],
			'nicht doch' => ['nicht doch'],
			'%core.debug%' => ['%core.debug%'],
			'foo' => ['foo'],
			'(int)2' => [2],
			'(string)2' => ['2']
		];
	}

	public function testDontCastOnExport(): void {
		$testValues = [
			['original' => 'false', 'casted' => false],
			['original' => 'true', 'casted' => true],
		];

		foreach($testValues as $value) {
			$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['' => 'invalid argument'], ['export' => 'exported']);
			$rd = $this->newWebRequest(['bool' => $value['original']]);
			$result = $validator->execute($rd);
			$rd = $validator->getMutatedRequest() ?? $rd;
			$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded.');
			$this->assertSame($value['casted'], $rd->getParameter('exported'), 'Failed asserting that the exported value is casted');
			$this->assertSame($value['original'], $rd->getParameter('bool'), 'Failed asserting that the validated value is untouched');
		}
	}

	public function testCastOnMissingExport(): void {
		$testValues = [
			['original' => 'false', 'casted' => false],
			['original' => 'true', 'casted' => true],
		];

		foreach($testValues as $value) {
			$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['' => 'invalid argument']);
			$rd = $this->newWebRequest(['bool' => $value['original']]);
			$result = $validator->execute($rd);
			$rd = $validator->getMutatedRequest() ?? $rd;
			$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded.');
			$this->assertSame($value['casted'], $rd->getParameter('bool'), 'Failed asserting that the validated value is casted');
		}
	}
}

?>
