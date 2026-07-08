<?php

use Quiote\Exception\ValidatorException;
use Quiote\Validator\Validator;
use Quiote\Util\VirtualArrayPath;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class SampleValidator extends Validator
{
	/** @var array<int, VirtualArrayPath> */
	public array $bases = [];
	public bool $val_result = true;

	#[\Override]
    protected function validate(): bool { return $this->val_result; }

	#[\Override]
    protected function validateInBase(VirtualArrayPath $base): int { array_push($this->bases, $base); return parent::validateInBase($base); }

	public function getArgument($name = null): mixed { return parent::getArgument($name); }
}

class SampleValidator2 extends Validator
{
	public string $base = '';
	public int $val_result = 0;

	#[\Override]
    protected function validate(): bool { return true; }
	#[\Override]
    protected function validateInBase(VirtualArrayPath $base): int { $this->base = (string) $base; return $this->val_result; }
}

class ExportingSampleValidator extends Validator
{
	#[\Override]
    protected function validate(): bool { $this->export('test'); return true; }
}

class ValidatorTest extends BaseValidatorTest
{
	public function testInitialize(): void
	{
		$validator = new SampleValidator();
		$validator->initialize($this->getContext());
		$this->assertEquals($validator->getParameter('depends'), []);
		$this->assertEquals($validator->getParameter('provides'), []);
	}

	public function testInitializeWithParameters(): void
	{
		$parameters = [
			'depends'	=> ['test1', 'test2', 'test3'],
			'provides'	=> ['foo', 'bar'],
		];
		$validator = new SampleValidator();
		$validator->initialize($this->getContext(), $parameters, ['test']);
		$this->assertEquals($validator->getParameter('depends'), ['test1', 'test2', 'test3']);
		$this->assertEquals($validator->getParameter('provides'), ['foo', 'bar']);
		$this->assertEquals($validator->getArgument(), 'test');
	}

	public function testMapErrorCode(): void
	{
		$this->assertEquals(Validator::mapErrorCode('info'), Validator::INFO);
		$this->assertEquals(Validator::mapErrorCode('none'), Validator::NONE);
		$this->assertEquals(Validator::mapErrorCode('silent'), Validator::NONE);
		$this->assertEquals(Validator::mapErrorCode('notice'), Validator::NOTICE);
		$this->assertEquals(Validator::mapErrorCode('error'), Validator::ERROR);
		$this->assertEquals(Validator::mapErrorCode('critical'), Validator::CRITICAL);
		$this->assertEquals(Validator::mapErrorCode('cRiTiCaL'), Validator::CRITICAL);

		try {
			Validator::mapErrorCode('foo');
			$this->fail();
		} catch(ValidatorException $e) {
			$this->assertEquals($e->getMessage(), 'unknown error code: foo');
		}
	}

	public function testExport(): void
	{
		$res = $this->executeValidator(ExportingSampleValidator::class, 'test', [], [
			'export' => 'foo',
		]);
		$this->assertEquals($res['rd']->getParameter('foo'), 'test');
	}

	public function testExportSeverity(): void
	{
		$res = $this->executeValidator(ExportingSampleValidator::class, 'test', [], [
			'export' => 'foo',
		]);
		$ar = $res['vm']->getReport()->getArgumentResults();
		$this->assertEquals($ar['parameters/foo'][0]['severity'], Validator::SUCCESS);

		$res = $this->executeValidator(ExportingSampleValidator::class, 'test', [], [
			'export'          => 'foo',
			'export_severity' => -1, // Use the actual value instead of a string
		]);
		$ar = $res['vm']->getReport()->getArgumentResults();
		$this->assertEquals($ar['parameters/foo'][0]['severity'], -1);
	}
}

?>
