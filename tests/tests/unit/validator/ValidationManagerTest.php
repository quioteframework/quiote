<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\DependencyManager;
use Quiote\Util\VirtualArrayPath;
use Quiote\Validator\Validator;

class MyValidationManager extends ValidationManager
{
	public function getChildren() { return $this->children; }
}

class ValidationManagerTest extends UnitTestCase 
{
	private $_vm = null;
	private $_context = null;
	
	#[\Override]
    public function setUp(): void
	{
		$this->_context = $this->getContext();
		$this->_vm = $this->_context->createInstanceFor('validation_manager');
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->_vm = null;
		$this->_context = null;
	}
	
	public function testGetContext()
	{
		$this->assertSame($this->_vm->getContext(), $this->_context);
	}
	
	public function testClear()
	{
		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$val = $vm->createValidator('DummyValidator', []);
		
		$this->assertFalse($val->shutdown);
		$vm->clear();
		$this->assertTrue($val->shutdown);
		$this->assertEquals($vm->getChildren(), []);
	}
	
	public function testAddChild()
	{
		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$val = new DummyValidator();
		$val->initialize($this->getContext(), ['name' => 'val']);

		$this->assertEquals($vm->getChildren(), []);
		$vm->addChild($val);
		$this->assertEquals($vm->getChildren(), ['val' => $val]);
	}
	
	public function testgetDependencyManager()
	{
		$this->assertTrue($this->_vm->getDependencyManager() instanceof DependencyManager);
	}
	
	public function testgetBase()
	{
		$this->_vm->removeParameter('base');
		$this->assertEquals($this->_vm->getBase(), new VirtualArrayPath(''));
		$this->_vm->setParameter('base', '');
		$this->assertEquals($this->_vm->getBase(), new VirtualArrayPath(''));
		$this->_vm->setParameter('base', 'foo[bar]');
		$this->assertEquals($this->_vm->getBase(), new VirtualArrayPath('foo[bar]'));
	}
	
	public function testExecute()
	{
		$val1 = $this->_vm->createValidator('DummyValidator', []);
		$val2 = $this->_vm->createValidator('DummyValidator', []);
		
		$val1->val_result = true;
		$val2->val_result = true;
		
		$this->assertTrue($this->_vm->execute($this->newWebRequest()));
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$this->_vm->clear();
		$val1->clear();
		$val2->clear();

		$val1->val_result = false;
		$val1->setParameter('severity', 'none');
		$this->_vm->registerValidators([$val1, $val2]);
		$this->assertTrue($this->_vm->execute($this->newWebRequest()));
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$this->_vm->clear();
		$val1->clear();
		$val2->clear();
		
		$val1->setParameter('severity', 'error');
		$this->_vm->registerValidators([$val1, $val2]);
		$this->assertFalse($this->_vm->execute($this->newWebRequest()));
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$this->_vm->clear();
		$val1->clear();
		$val2->clear();
		
		$val1->setParameter('severity', 'critical');
		$this->_vm->registerValidators([$val1, $val2]);
		$this->assertFalse($this->_vm->execute($this->newWebRequest()));
		$this->assertTrue($val1->validated);
		$this->assertFalse($val2->validated);
		$this->_vm->clear();
		$val1->clear();
		$val2->clear();
	}
	
	public function testShutdown()
	{
		$val = $this->_vm->createValidator('DummyValidator', []);
		
		$this->assertFalse($val->shutdown);
		$this->_vm->shutdown();
		$this->assertTrue($val->shutdown);
	}
	
	public function testRegisterValidators()
	{
		$val1 = $this->_vm->createValidator('DummyValidator', [], [], ['name' => 'val1']);
		$val2 = $this->_vm->createValidator('DummyValidator', [], [], ['name' => 'val2']);
		
		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$this->assertEquals($vm->getChildren(), []);
		$vm->registerValidators([$val1, $val2]);
		$this->assertEquals($vm->getChildren(), ['val1' => $val1, 'val2' => $val2]);
	}
	
	public function testGetResult()
	{
		// getReport()->getResult() is the modern replacement for the deprecated
		// ValidationManager::getResult(); it returns null for a manager that
		// has not validated anything yet (the deprecated accessor coalesced that
		// to Validator::NOT_PROCESSED).
		$this->assertNull($this->_vm->getReport()->getResult());
	}
	
	public function testTransfersDependTokens()
	{
		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$validator = $this->_vm->createValidator('DummyValidator', [], [], ['provides' => 'provide-token']);
		$vm->registerValidators([$validator]);
		$vm->execute($this->newWebRequest());
		$this->assertEquals(['provide-token' => true], $vm->getReport()->getDependTokens());
	}
}
?>