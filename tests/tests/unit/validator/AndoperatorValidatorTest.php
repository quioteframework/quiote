<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\AndoperatorValidator;
use Quiote\Validator\Validator;
use Sandbox\Testing\ExportingDummyValidator;

class AndoperatorValidatorTest extends UnitTestCase
{
	public function testExecute(): void
	{
		$vm = $this->getContext()->getContainer()->get(\Quiote\Validator\ValidationManager::class);
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

	/**
	 * Regression guard: WebRequest is immutable, so a child's export() (see ExportingDummyValidator)
	 * only replaces the CHILD's own copy of the request, not this operator's own -- getMutatedRequest()
	 * must fold it back in, or ValidationManager::execute() (which only reads getMutatedRequest() off
	 * its direct children, never a validator nested one level deeper inside an or/and/xor/not group)
	 * never sees the export. Also verifies the second child sees the first child's export.
	 */
	public function testExportFromAnEarlierChildReachesTheOperatorsOwnMutatedRequest(): void
	{
		$vm = $this->getContext()->getContainer()->get(\Quiote\Validator\ValidationManager::class);
		$vm->clear();
		$o = $vm->createValidator(AndoperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator(ExportingDummyValidator::class, [], [], ['severity' => 'error']);
		$val2 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$o->registerValidators([$val1, $val2]);

		$val1->val_result = true;
		$val2->val_result = true;
		$this->assertEquals(Validator::SUCCESS, $o->execute($this->newWebRequest()));

		$mutated = $o->getMutatedRequest();
		$this->assertNotNull($mutated);
		$this->assertSame('exported-value', $mutated->getParameter('ExportedByChild'));
	}
}
?>
