<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviOperatorValidator;
use Agavi\Validator\AgaviValidator;

class MyOperatorValidator extends AgaviOperatorValidator
{
	public $checked = false;
	
	protected function validate() {return true;}
	protected function checkValidSetup() {$this->checked = true;}
	public function getChildren() {return $this->children;}
}

class AgaviOperatorValidatorTest extends AgaviUnitTestCase
{
	private $context;
	private $vm;
	
	#[\Override]
    public function setUp(): void
	{
		$this->context = $this->getContext();
		$this->vm = $this->context->createInstanceFor('validation_manager');
	}
	
	#[\Override]
    public function tearDown(): void
	{
		$this->vm = null;
		$this->context = null;
	}
	
	public function testShutdown()
	{
		$val = $this->vm->createValidator('DummyValidator', []);
		$v = $this->vm->createValidator('MyOperatorValidator', []);
		$v->addChild($val);
		
		$this->assertFalse($val->shutdown);
		$v->shutdown();
		$this->assertTrue($val->shutdown);
	}
	
	public function testRegisterValidators()
	{
		$val1 = $this->vm->createValidator('DummyValidator', [], [], ['name' => 'val1']);
		$val2 = $this->vm->createValidator('DummyValidator', [], [], ['name' => 'val2']);
		
		$v = $this->vm->createValidator('MyOperatorValidator', [], [], []);
		$this->assertEquals($v->getChildren(), []);
		$v->registerValidators([$val1, $val2]);
		$this->assertEquals($v->getChildren(), ['val1' => $val1, 'val2' =>$val2]);
	}
	
	public function testAddChild()
	{
		$val = $this->vm->createValidator('DummyValidator', [], [], ['name' => 'val']);
		$v = $this->vm->createValidator('MyOperatorValidator', []);

		$this->assertEquals($v->getChildren(), []);
		$v->addChild($val);
		$this->assertEquals($v->getChildren(), ['val' => $val]);
	}
	
	public function testExecute()
	{
		$v = $this->vm->createValidator('MyOperatorValidator', []);
		$this->assertFalse($v->checked);
		$this->assertEquals($v->execute($this->newWebRequest()), AgaviValidator::SUCCESS);
		$this->assertTrue($v->checked);
	}
}
?>
