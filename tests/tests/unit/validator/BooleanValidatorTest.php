<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

class BooleanValidatorTest extends UnitTestCase
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
	
	#[DataProvider('validValues')]
	public function testAccept($value, $expectedResult)
	{
		$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['invalid argument'], []);
		$rd = $this->newWebRequest(['bool' => $value]);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded.');
		$this->assertEquals($expectedResult, $rd->getParameter('bool'), 'Failed asserting that the validated value is the expected value');
	}

	public static function validValues() {
		
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
	public function testNotAccept($value)
	{
		$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['invalid argument'], ['export' => 'exported']);
		// Pre-whitelist export target so reading it after failed validation returns null instead of throwing.
		$rd = $this->newWebRequest(['bool' => $value], ['exported']);
		$result = $validator->execute($rd);
		$this->assertEquals(Validator::ERROR, $result, 'Failed asserting that the validation failed.');
		$this->assertNull($rd->getParameter('exported'), 'Failed asserting that the value is not exported');
		$this->assertEquals($value, $rd->getParameter('bool'), 'Failed asserting that the validated value is the original value');
	}
	
	public static function invalidValues() {
		return [
			'nä' => ['nä'],
			'nicht doch' => ['nicht doch'], 
			'%core.debug%' => ['%core.debug%'], 
			'foo' => ['foo'],
			'(int)2' => [2],
			'(string)2' => ['2']
		];
	}
	
	public function testDontCastOnExport() {
		$testValues = [
			['original' => 'false', 'casted' => false],
			['original' => 'true', 'casted' => true],
		];
		
		foreach($testValues as $value) {
			$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['invalid argument'], ['export' => 'exported']);
			$rd = $this->newWebRequest(['bool' => $value['original']]);
			$result = $validator->execute($rd);
			$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded.');
			$this->assertSame($value['casted'], $rd->getParameter('exported'), 'Failed asserting that the exported value is casted');
			$this->assertSame($value['original'], $rd->getParameter('bool'), 'Failed asserting that the validated value is untouched');
		}
	}
	
	public function testCastOnMissingExport() {
		$testValues = [
			['original' => 'false', 'casted' => false],
			['original' => 'true', 'casted' => true],
		];
		
		foreach($testValues as $value) {
			$validator = $this->vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['bool'], ['invalid argument']);
			$rd = $this->newWebRequest(['bool' => $value['original']]);
			$result = $validator->execute($rd);
			$this->assertEquals(Validator::SUCCESS, $result, 'Failed asserting that the validation succeeded.');
			$this->assertSame($value['casted'], $rd->getParameter('bool'), 'Failed asserting that the validated value is casted');
		}
	}
}

?>