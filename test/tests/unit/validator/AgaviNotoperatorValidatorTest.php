<?php

use Agavi\Exception\AgaviValidatorException;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviNotoperatorValidator;
use Agavi\Validator\AgaviValidator;

class AgaviNotoperatorValidatorTest extends AgaviUnitTestCase
{
	public function testvalidate()
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$vm->clear();
		$o = $vm->createValidator(AgaviNotoperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$o->registerValidators([$val1]);
		
		// 1st test: successful
		$val1->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::ERROR);
		$this->assertTrue($val1->validated);
		$val1->clear();

		// 2nd test: failure
		$val1->val_result = false;
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::SUCCESS);
		$this->assertTrue($val1->validated);
		$val1->clear();

		// 3rd test: critical
		$val1->val_result = false;
		$val1->setParameter('severity', 'critical');
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::CRITICAL);
		$this->assertTrue($val1->validated);
		$val1->clear();
	}
	
	public function testcheckValidSetup()
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$vm->clear();
		$o = $vm->createValidator(AgaviNotoperatorValidator::class, [], [], ['severity' => 'error']);
		
		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val2 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		
		try {
			$o->execute($this->newWebRequest());
			$this->fail();
		} catch(AgaviValidatorException $e) {
			$this->assertEquals($e->getMessage(), 'NOT allows only 1 child validator');
		}
		$o->addChild($val1);
		
		$o->addChild($val2);
		try {
			$o->execute($this->newWebRequest());
			$this->fail();
		} catch(AgaviValidatorException $e) {
			$this->assertEquals($e->getMessage(), 'NOT allows only 1 child validator');
		}
	}
}
?>
