<?php

use Quiote\Exception\ValidatorException;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Validator\Validator;
use Quiote\Util\VirtualArrayPath;

class SampleValidator extends Validator
{
	public $bases = [];
	public $val_result = true;
	private $AffectedFieldNames;

	protected function validate() { return $this->val_result; }
	
	#[\Override]
    public function getBase() { return $this->curBase->__toString(); }
	public function getParent() {return $this->parentContainer; }
	public function getData2($parameter) { return $this->getData($parameter); }
	public function getData3() { return $this->getData(null); }
	#[\Override]
    public function getArgument($name = null) { return parent::getArgument(); }
	public function throwError2($index = 'error', $ignoreAsMessage = false, $affectedFields = null, $backupError = null)
	{
		$this->throwError($index, $ignoreAsMessage, $affectedFields, $backupError);
	}
	public function getAffectedFields2($fields) { $this->AffectedFieldNames = $fields; return $this->getAffectedFields(); }
	public function export2($value) { $this->export($value); }
	
	#[\Override]
    protected function validateInBase(VirtualArrayPath $base) { array_push($this->bases, $base); return parent::validateInBase($base); }
	public function validateInBase2($base) { return $this->validateInBase($base); }
}

class SampleValidator2 extends Validator
{
	public $base = '';
	public $val_result = 0;
	
	protected function validate() { return true; }
	#[\Override]
    protected function validateInBase(VirtualArrayPath $base) { $this->base = $base; return $this->val_result; }
}

class ExportingSampleValidator extends Validator
{
	protected function validate() { $this->export('test'); return true; }
}

class ValidatorTest extends BaseValidatorTest
{
	private $_vm = null;
					
	#[\Override]
    public function setUp(): void
	{
		$this->_vm = $this->getContext()->createInstanceFor('validation_manager');
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->_vm = null;
	}

	public function testInitialize()
	{
		$validator = new SampleValidator();
		$validator->initialize($this->getContext());
		$this->assertEquals($validator->getParameter('depends'), []);
		$this->assertEquals($validator->getParameter('provides'), []);
	}
	
	public function testInitializeWithParameters()
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
	
	public function testMapErrorCode()
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

	public function testExport()
	{
		$res = $this->executeValidator('ExportingSampleValidator', 'test', [], [
			'export' => 'foo',
		]);
		$this->assertEquals($res['rd']->getParameter('foo'), 'test');
	}

	public function testExportSeverity()
	{
		$res = $this->executeValidator('ExportingSampleValidator', 'test', [], [
			'export' => 'foo',
		]);
		$ar = $res['vm']->getReport()->getArgumentResults();
		$this->assertEquals($ar['parameters/foo'][0]['severity'], Validator::SUCCESS);

		$res = $this->executeValidator('ExportingSampleValidator', 'test', [], [
			'export'          => 'foo',
			'export_severity' => -1, // Use the actual value instead of a string
		]);
		$ar = $res['vm']->getReport()->getArgumentResults();
		$this->assertEquals($ar['parameters/foo'][0]['severity'], -1);
	}
}

?>