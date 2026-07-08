<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\AndoperatorValidator;
use Quiote\Validator\Validator;

class AndoperatorValidatorTest extends UnitTestCase
{
	public function testExecute(): void
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$vm->clear();
		$o = $vm->createValidator(AndoperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val1->val_result = true;
		$val2 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val2->val_result = true;

		$o->registerValidators([$val1, $val2]);

		$this->assertEquals($o->execute($this->newWebRequest()), Validator::SUCCESS);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val1->validated);

		$val1->clear();
		$val2->clear();

		$o->setParameter('break', true);
		$val1->val_result = false;

		$this->assertEquals($o->execute($this->newWebRequest()), Validator::ERROR);
		$this->assertTrue($val1->validated);
		$this->assertFalse($val2->validated);

		$val1->clear();
		$val2->clear();

		$o->setParameter('break', false);

		$this->assertEquals($o->execute($this->newWebRequest()), Validator::ERROR);
		$this->assertTrue($val1->validated);
		$this->assertTrue($val2->validated);

		$val1->clear();
		$val2->clear();

		$val1->setParameter('severity', 'critical');

		$this->assertEquals($o->execute($this->newWebRequest()), Validator::CRITICAL);
		$this->assertEquals($vm->getReport()->getResult(), Validator::CRITICAL);
		$this->assertTrue($val1->validated);
		$this->assertFalse($val2->validated);
	}
}
?>
