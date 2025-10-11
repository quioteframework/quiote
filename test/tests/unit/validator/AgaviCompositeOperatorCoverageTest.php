<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Validator\AgaviValidator;
use Agavi\Validator\AgaviAndoperatorValidator;
use Agavi\Validator\AgaviOroperatorValidator;
use Agavi\Validator\AgaviXoroperatorValidator;
use Agavi\Validator\AgaviNotoperatorValidator;

require_once __DIR__ . '/../../../lib/validator/DummyValidator.class.php';

/**
 * Composite / nested operator validator coverage focusing on:
 *  - Short-circuit break behavior for AND/OR with break parameter
 *  - Propagation of CRITICAL severity from children
 *  - Nested operator evaluation order (AND containing OR, NOT wrapping AND)
 *  - XOR exact-two constraint & error path when both succeed / both fail
 *  - NOT inversion behavior with child success vs failure
 */
class AgaviCompositeOperatorCoverageTest extends AgaviUnitTestCase
{
    private function vm(array $params = []): AgaviValidationManager
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        foreach($params as $k=>$v){ $vm->setParameter($k,$v);} return $vm;
    }

    private function dv(AgaviValidationManager $vm, string $name, bool $result = true, string $severity = 'error'): DummyValidator
    {
        /** @var DummyValidator $d */ $d = $vm->createValidator('DummyValidator', [], [], ['name'=>$name,'severity'=>$severity]);
        $d->val_result = $result; return $d;
    }

    public function testAndShortCircuitBreakAndCritical(): void
    {
        $vm = $this->vm();
        /** @var AgaviAndoperatorValidator $and */ $and = $vm->createValidator(AgaviAndoperatorValidator::class, [], [], ['name'=>'AND','break'=>true,'severity'=>'error']);
        $c1 = $this->dv($vm,'c1', false, 'error');
        $c2 = $this->dv($vm,'c2', true, 'error');
        $and->registerValidators([$c1,$c2]);
    $req = $this->newWebRequest();
    $this->assertEquals(AgaviValidator::ERROR, $and->execute($req));
        $this->assertTrue($c1->validated);
        $this->assertFalse($c2->validated,'Break should prevent second child execution');

        // Critical child triggers immediate break regardless of break flag
        $vm2 = $this->vm();
        /** @var AgaviAndoperatorValidator $and2 */ $and2 = $vm2->createValidator(AgaviAndoperatorValidator::class, [], [], ['name'=>'AND2','break'=>false,'severity'=>'error']);
        $crit = $this->dv($vm2,'crit', false, 'critical');
        $after = $this->dv($vm2,'after', true, 'error');
        $and2->registerValidators([$crit,$after]);
    $req2 = $this->newWebRequest();
    $this->assertEquals(AgaviValidator::CRITICAL, $and2->execute($req2));
        $this->assertTrue($crit->validated);
        $this->assertFalse($after->validated,'Critical child aborts remaining execution');
    }

    public function testOrShortCircuitOnSuccessAndCriticalPropagation(): void
    {
        $vm = $this->vm();
        /** @var AgaviOroperatorValidator $or */ $or = $vm->createValidator(AgaviOroperatorValidator::class, [], [], ['name'=>'OR','break'=>true,'severity'=>'error']);
        $first = $this->dv($vm,'first', true, 'error');
        $second = $this->dv($vm,'second', true, 'error');
        $or->registerValidators([$first,$second]);
    $this->assertEquals(AgaviValidator::SUCCESS, $or->execute($this->newWebRequest()));
        $this->assertTrue($first->validated);
        $this->assertFalse($second->validated,'Break on success prevents second validation');

        // Critical child appearing first stops evaluation and fails overall
        $vm2 = $this->vm();
        /** @var AgaviOroperatorValidator $or2 */ $or2 = $vm2->createValidator(AgaviOroperatorValidator::class, [], [], ['name'=>'OR2','break'=>false,'severity'=>'error']);
        $crit = $this->dv($vm2,'crit', false, 'critical');
        $ok = $this->dv($vm2,'ok', true, 'error');
        $or2->registerValidators([$crit,$ok]);
    $this->assertEquals(AgaviValidator::CRITICAL, $or2->execute($this->newWebRequest()),'Critical short-circuits and fails OR');
        $this->assertTrue($crit->validated);
        $this->assertFalse($ok->validated);
    }

    public function testXorExactOneSuccessPaths(): void
    {
        $vm = $this->vm();
        /** @var AgaviXoroperatorValidator $xor */ $xor = $vm->createValidator(AgaviXoroperatorValidator::class, [], [], ['name'=>'XOR','severity'=>'error']);
        $a = $this->dv($vm,'a', true,'error');
        $b = $this->dv($vm,'b', false,'error');
        $xor->registerValidators([$a,$b]);
    $this->assertEquals(AgaviValidator::SUCCESS, $xor->execute($this->newWebRequest()));

        $vm2 = $this->vm();
        /** @var AgaviXoroperatorValidator $xor2 */ $xor2 = $vm2->createValidator(AgaviXoroperatorValidator::class, [], [], ['name'=>'XOR2','severity'=>'error']);
        $c = $this->dv($vm2,'c', true,'error');
        $d = $this->dv($vm2,'d', true,'error');
        $xor2->registerValidators([$c,$d]);
    $this->assertNotEquals(AgaviValidator::SUCCESS, $xor2->execute($this->newWebRequest()),'Both succeed -> XOR fails');

        $vm3 = $this->vm();
        /** @var AgaviXoroperatorValidator $xor3 */ $xor3 = $vm3->createValidator(AgaviXoroperatorValidator::class, [], [], ['name'=>'XOR3','severity'=>'error']);
        $e = $this->dv($vm3,'e', false,'error');
        $f = $this->dv($vm3,'f', false,'error');
        $xor3->registerValidators([$e,$f]);
    $this->assertNotEquals(AgaviValidator::SUCCESS, $xor3->execute($this->newWebRequest()),'Both fail -> XOR fails');

        // Critical in either child aborts evaluation
        $vm4 = $this->vm();
        /** @var AgaviXoroperatorValidator $xor4 */ $xor4 = $vm4->createValidator(AgaviXoroperatorValidator::class, [], [], ['name'=>'XOR4','severity'=>'error']);
        $g = $this->dv($vm4,'g', false,'critical');
        $h = $this->dv($vm4,'h', true,'error');
        $xor4->registerValidators([$g,$h]);
    $this->assertEquals(AgaviValidator::CRITICAL, $xor4->execute($this->newWebRequest()),'Critical first child causes immediate failure');
        $this->assertTrue($g->validated);
        $this->assertFalse($h->validated);
    }

    public function testNotInversionAndSuccessMarking(): void
    {
        $vm = $this->vm();
        /** @var AgaviNotoperatorValidator $not */ $not = $vm->createValidator(AgaviNotoperatorValidator::class, [], [], ['name'=>'NOT','severity'=>'error']);
        $child = $this->dv($vm,'child', false,'error');
        $not->registerValidators([$child]);
    $this->assertEquals(AgaviValidator::SUCCESS, $not->execute($this->newWebRequest()),'Child failure -> NOT success');

        $vm2 = $this->vm();
        /** @var AgaviNotoperatorValidator $not2 */ $not2 = $vm2->createValidator(AgaviNotoperatorValidator::class, [], [], ['name'=>'NOT2','severity'=>'error']);
        $child2 = $this->dv($vm2,'child2', true,'error');
        $not2->registerValidators([$child2]);
    $this->assertNotEquals(AgaviValidator::SUCCESS, $not2->execute($this->newWebRequest()),'Child success -> NOT failure');

        $vm3 = $this->vm();
        /** @var AgaviNotoperatorValidator $not3 */ $not3 = $vm3->createValidator(AgaviNotoperatorValidator::class, [], [], ['name'=>'NOT3','severity'=>'error']);
        $crit = $this->dv($vm3,'crit', false,'critical');
        $not3->registerValidators([$crit]);
    $this->assertNotEquals(AgaviValidator::SUCCESS, $not3->execute($this->newWebRequest()),'Critical child -> NOT failure');
    }

    public function testNestedCompositeEvaluationOrder(): void
    {
        // Build: AND( OR(success, fail[skipped via break]), NOT(fail) ) => AND succeeds overall
        $vm = $this->vm();
        /** @var AgaviAndoperatorValidator $and */ $and = $vm->createValidator(AgaviAndoperatorValidator::class, [], [], ['name'=>'AND_TOP','break'=>false,'severity'=>'error']);
        /** @var AgaviOroperatorValidator $or */ $or = $vm->createValidator(AgaviOroperatorValidator::class, [], [], ['name'=>'OR_IN','break'=>true,'severity'=>'error']);
        $orFirst = $this->dv($vm,'orFirst', true,'error');
        $orSecond = $this->dv($vm,'orSecond', false,'error');
        $or->registerValidators([$orFirst,$orSecond]);
        /** @var AgaviNotoperatorValidator $not */ $not = $vm->createValidator(AgaviNotoperatorValidator::class, [], [], ['name'=>'NOT_IN','severity'=>'error']);
        $notChild = $this->dv($vm,'notChild', false,'error'); // failing child -> NOT succeeds
        $not->registerValidators([$notChild]);
        $and->registerValidators([$or,$not]);
    $this->assertEquals(AgaviValidator::SUCCESS, $and->execute($this->newWebRequest()));
        $this->assertTrue($orFirst->validated);
        $this->assertFalse($orSecond->validated,'OR break skip second');
        $this->assertTrue($notChild->validated);

        // Now make nested NOT fail so AND fails
        $vm2 = $this->vm();
        /** @var AgaviAndoperatorValidator $and2 */ $and2 = $vm2->createValidator(AgaviAndoperatorValidator::class, [], [], ['name'=>'AND_TOP2','break'=>false,'severity'=>'error']);
        /** @var AgaviOroperatorValidator $or2 */ $or2 = $vm2->createValidator(AgaviOroperatorValidator::class, [], [], ['name'=>'OR_IN2','break'=>true,'severity'=>'error']);
        $or2First = $this->dv($vm2,'or2First', true,'error');
        $or2Second = $this->dv($vm2,'or2Second', false,'error');
        $or2->registerValidators([$or2First,$or2Second]);
        /** @var AgaviNotoperatorValidator $not2 */ $not2 = $vm2->createValidator(AgaviNotoperatorValidator::class, [], [], ['name'=>'NOT_IN2','severity'=>'error']);
        $not2Child = $this->dv($vm2,'not2Child', true,'error'); // succeeding child -> NOT fails
        $not2->registerValidators([$not2Child]);
        $and2->registerValidators([$or2,$not2]);
    $this->assertNotEquals(AgaviValidator::SUCCESS, $and2->execute($this->newWebRequest()));
    }
}
