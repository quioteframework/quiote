<?php

use Agavi\Exception\AgaviValidatorException;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviValidator;
use Agavi\Validator\AgaviXoroperatorValidator;

class AgaviXoroperatorValidatorTest extends AgaviUnitTestCase
{
	public function testvalidate()
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$vm->clear();
		$o = $vm->createValidator(AgaviXoroperatorValidator::class, [], [], ['severity' => 'error']);
		
		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val2 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$o->registerValidators([$val1, $val2]);
		
		// 1st test: both successful
		$val1->val_result = true;
		$val2->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::ERROR);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 2nd test: first successful
		$val1->val_result = true;
		$val2->val_result = false;
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::SUCCESS);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 3rd test: last successful
		$val1->val_result = false;
		$val2->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::SUCCESS);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 4th test: none successful
		$val1->val_result = false;
		$val2->val_result = false;
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::ERROR);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 5th test: first results critical
		$val1->val_result = false;
		$val1->setParameter('severity', 'critical');
		$val2->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::CRITICAL);
		$this->assertTrue($val1->validated);
		$this->assertFalse($val2->validated);
		$val1->setParameter('severity', 'error');
		$val1->clear();
		$val2->clear();

		// 5th test: last results critical
		$val1->val_result = true;
		$val2->val_result = false;
		$val2->setParameter('severity', 'critical');
		$this->assertEquals($o->execute($this->newWebRequest()), AgaviValidator::CRITICAL);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();
	}
	
	public function testcheckValidSetup()
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$vm->clear();
		$o = $vm->createValidator(AgaviXoroperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val2 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val3 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		
		$o->addChild($val1);
		try {
			$o->execute($this->newWebRequest());
			$this->fail();
		} catch(AgaviValidatorException $e) {
			$this->assertEquals($e->getMessage(), 'XOR allows only exact 2 child validators');
		}
		
		$o->addChild($val2);
		$o->execute($this->newWebRequest());
		
		$o->addChild($val3);
		try {
			$o->execute($this->newWebRequest());
			$this->fail();
		} catch(AgaviValidatorException $e) {
			$this->assertEquals($e->getMessage(), 'XOR allows only exact 2 child validators');
		}
	}
}
?>
