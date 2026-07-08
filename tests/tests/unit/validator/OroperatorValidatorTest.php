<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\OroperatorValidator;
use Quiote\Validator\Validator;

class OroperatorValidatorTest extends UnitTestCase
{
	public function testvalidate(): void
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$vm->clear();
		$o = $vm->createValidator(OroperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val2 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$o->registerValidators([$val1, $val2]);

		// 1st test: both successful
		$val1->val_result = true;
		$val2->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::SUCCESS);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 2nd test: first successful
		$val1->val_result = true;
		$val2->val_result = false;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::SUCCESS);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 3rd test: last successful
		$val1->val_result = false;
		$val2->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::SUCCESS);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 4th test: none successful
		$val1->val_result = false;
		$val2->val_result = false;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::ERROR);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);
		$val1->clear();
		$val2->clear();

		// 5th test: successful with break enabled
		$o->setParameter('break', true);
		$val1->val_result = true;
		$val2->val_result = false;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::SUCCESS);
		$this->assertTrue($val1->validated);
		$this->assertFalse($val2->validated);
		$val1->clear();
		$val2->clear();

		// 6th test: fails because of CRITICAL result
		$o->setParameter('break', false);
		$val1->val_result = false;
		$val1->setParameter('severity', 'critical');
		$val2->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::CRITICAL);
		$this->assertTrue($val1->validated);
		$this->assertFalse($val2->validated);
		$this->assertEquals($vm->getReport()->getResult(), Validator::CRITICAL);
		$val1->clear();
		$val2->clear();
	}
}
?>
