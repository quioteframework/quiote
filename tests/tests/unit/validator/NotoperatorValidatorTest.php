<?php

use Quiote\Exception\ValidatorException;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\NotoperatorValidator;
use Quiote\Validator\Validator;
use Sandbox\Testing\ExportingDummyValidator;

class NotoperatorValidatorTest extends UnitTestCase
{
	public function testvalidate(): void
	{
		$vm = $this->getContext()->getContainer()->get(\Quiote\Validator\ValidationManager::class);
		$vm->clear();
		$o = $vm->createValidator(NotoperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$o->registerValidators([$val1]);

		// 1st test: successful
		$val1->val_result = true;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::ERROR);
		$this->assertTrue($val1->validated);
		$val1->clear();

		// 2nd test: failure
		$val1->val_result = false;
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::SUCCESS);
		$this->assertTrue($val1->validated);
		$val1->clear();

		// 3rd test: critical
		$val1->val_result = false;
		$val1->setParameter('severity', 'critical');
		$this->assertEquals($o->execute($this->newWebRequest()), Validator::CRITICAL);
		$this->assertTrue($val1->validated);
		$val1->clear();
	}

	/**
	 * Regression guard: WebRequest is immutable, so a child's export() (see ExportingDummyValidator)
	 * only replaces the CHILD's own copy of the request, not this operator's own -- getMutatedRequest()
	 * must fold it back in, or ValidationManager::execute() (which only reads getMutatedRequest() off
	 * its direct children, never a validator nested one level deeper inside an or/and/xor/not group)
	 * never sees the export. The child here exports on success, which is NOT's failing branch --
	 * propagation must happen regardless of which branch NOT itself takes.
	 */
	public function testExportFromTheChildReachesTheOperatorsOwnMutatedRequestEvenWhenNotFails(): void
	{
		$vm = $this->getContext()->getContainer()->get(\Quiote\Validator\ValidationManager::class);
		$vm->clear();
		$o = $vm->createValidator(NotoperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator(ExportingDummyValidator::class, [], [], ['severity' => 'error']);
		$o->registerValidators([$val1]);

		$val1->val_result = true;
		$this->assertEquals(Validator::ERROR, $o->execute($this->newWebRequest()));

		$mutated = $o->getMutatedRequest();
		$this->assertNotNull($mutated);
		$this->assertSame('exported-value', $mutated->getParameter('ExportedByChild'));
	}

	public function testcheckValidSetup(): void
	{
		$vm = $this->getContext()->getContainer()->get(\Quiote\Validator\ValidationManager::class);
		$vm->clear();
		$o = $vm->createValidator(NotoperatorValidator::class, [], [], ['severity' => 'error']);

		$val1 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);
		$val2 = $vm->createValidator('DummyValidator', [], [], ['severity' => 'error']);

		try {
			$o->execute($this->newWebRequest());
			$this->fail();
		} catch(ValidatorException $e) {
			$this->assertEquals($e->getMessage(), 'NOT allows only 1 child validator');
		}
		$o->addChild($val1);

		$o->addChild($val2);
		try {
			$o->execute($this->newWebRequest());
			$this->fail();
		} catch(ValidatorException $e) {
			$this->assertEquals($e->getMessage(), 'NOT allows only 1 child validator');
		}
	}
}
?>
