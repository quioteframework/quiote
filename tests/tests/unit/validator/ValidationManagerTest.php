<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\DependencyManager;
use Quiote\Util\VirtualArrayPath;
use Quiote\Validator\Validator;

class MyValidationManager extends ValidationManager
{
	/** @return array<string, Validator> */
	public function getChildren(): array { return $this->children; }
}

class ValidationManagerTest extends UnitTestCase
{
	private ValidationManager $_vm;
	private Context $_context;

	#[\Override]
    public function setUp(): void
	{
		$this->_context = $this->getContext();
		$this->_vm = $this->_context->createInstanceFor('validation_manager');
	}

	public function testGetContext(): void
	{
		$this->assertSame($this->_vm->getContext(), $this->_context);
	}

	public function testClear(): void
	{
		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$val = $vm->createValidator('DummyValidator', []);

		$this->assertFalse($val->shutdown);
		$vm->clear();
		$this->assertTrue($val->shutdown);
		$this->assertEquals($vm->getChildren(), []);
	}

	public function testAddChild(): void
	{
		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$val = new DummyValidator();
		$val->initialize($this->getContext(), ['name' => 'val']);

		$this->assertEquals($vm->getChildren(), []);
		$vm->addChild($val);
		$this->assertEquals($vm->getChildren(), ['val' => $val]);
	}

	public function testgetDependencyManager(): void
	{
		// Same DependencyManager instance is returned on repeated calls
		// (dependency tokens registered by one validator must be visible to
		// a sibling validator queried later in the same run).
		$this->assertSame($this->_vm->getDependencyManager(), $this->_vm->getDependencyManager());
	}

	public function testgetBase(): void
	{
		$this->_vm->removeParameter('base');
		$this->assertEquals($this->_vm->getBase(), new VirtualArrayPath(''));
		$this->_vm->setParameter('base', '');
		$this->assertEquals($this->_vm->getBase(), new VirtualArrayPath(''));
		$this->_vm->setParameter('base', 'foo[bar]');
		$this->assertEquals($this->_vm->getBase(), new VirtualArrayPath('foo[bar]'));
	}

	public function testExecute(): void
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

	public function testGetRawParameterSnapshotSurvivesPruningOfPartiallyFailedArgument(): void
	{
		// A field with two validators, one passing and one failing, ends up
		// whitelisted (isWhitelisted('name') === true) but its value is
		// scrubbed from the request entirely -- see
		// WebRequest::pruneParametersToValidated(): "an explicit failure
		// always wins". getRawParameterSnapshot() exists precisely so
		// FormPopulationEngine can still redisplay the submitted value in
		// this scenario.
		$passing = $this->_vm->createValidator('DummyValidator', ['name'], [], ['name' => 'lengthOk']);
		$passing->val_result = true;
		$failing = $this->_vm->createValidator('DummyValidator', ['name'], [], ['name' => 'notNumeric', 'severity' => 'error']);
		$failing->val_result = false;

		$req = $this->newWebRequest();
		$req = $req->withQueryParams(['name' => '12345']);

		$this->assertFalse($this->_vm->execute($req));
		$this->assertSame(['name' => '12345'], $this->_vm->getRawParameterSnapshot());

		$finalReq = $this->_vm->getContext()->getRequest();
		$this->assertNull($finalReq->getParameter('name', null), 'Value must be scrubbed from the request despite the raw snapshot retaining it');
	}

	public function testOrGroupWithLosingSiblingDoesNotPruneFieldOnOverallSuccess(): void
	{
		// or(passing, failing) on the same field: the group as a whole passes
		// (one branch succeeded), but before the fix, the failing sibling
		// leaf independently reported the field as failed straight to
		// ValidationManager (bypassing the group's own aggregate result),
		// and any-failure-wins pruning wiped the field's value even though
		// validation, as a whole, succeeded.
		$or = $this->_vm->createValidator(\Quiote\Validator\OroperatorValidator::class, [], [], ['name' => 'nameOr']);
		$passing = new DummyValidator();
		$passing->initialize($this->_context, ['name' => 'passingLeaf'], ['name']);
		$passing->val_result = true;
		$failing = new DummyValidator();
		$failing->initialize($this->_context, ['name' => 'failingLeaf', 'severity' => 'error'], ['name']);
		$failing->val_result = false;
		$or->registerValidators([$passing, $failing]);

		$req = $this->newWebRequest();
		$req = $req->withQueryParams(['name' => 'Bob']);

		$this->assertTrue($this->_vm->execute($req));

		$finalReq = $this->_vm->getContext()->getRequest();
		$this->assertSame('Bob', $finalReq->getParameter('name', null), 'Field must survive pruning when the operator group as a whole passed');
	}

	public function testXorGroupWithLosingSiblingDoesNotPruneFieldOnOverallSuccess(): void
	{
		// xor(a, b) on the same field: exactly one child succeeds, so the
		// group passes, but the losing sibling still independently reports
		// the field as failed unless the group's own SUCCESS verdict
		// overrides it before forwarding.
		$xor = $this->_vm->createValidator(\Quiote\Validator\XoroperatorValidator::class, [], [], ['name' => 'nameXor']);
		$a = new DummyValidator();
		$a->initialize($this->_context, ['name' => 'xorA'], ['name']);
		$a->val_result = true;
		$b = new DummyValidator();
		$b->initialize($this->_context, ['name' => 'xorB', 'severity' => 'error'], ['name']);
		$b->val_result = false;
		$xor->registerValidators([$a, $b]);

		$req = $this->newWebRequest();
		$req = $req->withQueryParams(['name' => 'Bob']);

		$this->assertTrue($this->_vm->execute($req));

		$finalReq = $this->_vm->getContext()->getRequest();
		$this->assertSame('Bob', $finalReq->getParameter('name', null), 'Field must survive pruning when the operator group as a whole passed');
	}

	public function testGetRawParameterSnapshotIsEmptyBeforeExecute(): void
	{
		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$this->assertSame([], $vm->getRawParameterSnapshot());
	}

	public function testShutdown(): void
	{
		$val = $this->_vm->createValidator('DummyValidator', []);

		$this->assertFalse($val->shutdown);
		$this->_vm->shutdown();
		$this->assertTrue($val->shutdown);
	}

	public function testRegisterValidators(): void
	{
		$val1 = $this->_vm->createValidator('DummyValidator', [], [], ['name' => 'val1']);
		$val2 = $this->_vm->createValidator('DummyValidator', [], [], ['name' => 'val2']);

		$vm = new MyValidationManager;
		$vm->initialize($this->_context);
		$this->assertEquals($vm->getChildren(), []);
		$vm->registerValidators([$val1, $val2]);
		$this->assertEquals($vm->getChildren(), ['val1' => $val1, 'val2' => $val2]);
	}

	public function testGetResult(): void
	{
		// getReport()->getResult() is the modern replacement for the deprecated
		// ValidationManager::getResult(); it returns null for a manager that
		// has not validated anything yet (the deprecated accessor coalesced that
		// to Validator::NOT_PROCESSED).
		$this->assertNull($this->_vm->getReport()->getResult());
	}

	public function testTransfersDependTokens(): void
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
