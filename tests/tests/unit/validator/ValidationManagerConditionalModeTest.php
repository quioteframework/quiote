<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

/**
 * Focused tests for MODE_CONDITIONAL pruning semantics.
 */
class ValidationManagerConditionalModeTest extends UnitTestCase
{
    private function newVm(string $mode): ValidationManager
    {
        /** @var ValidationManager $vm */
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->setParameter('mode', $mode);
        return $vm;
    }

    public function testZeroValidatorsKeepsParametersInConditionalMode(): void
    {
        $vm = $this->newVm(ValidationManager::MODE_CONDITIONAL);
        $req = $this->newWebRequest(['keep' => 'v','other' => 'x']);
        $this->assertTrue($vm->execute($req));
        $this->assertTrue($req->hasParameter('keep'));
        $this->assertTrue($req->hasParameter('other'));
    }

    public function testWithValidatorsPrunesUnvalidatedParameters(): void
    {
        $vm = $this->newVm(ValidationManager::MODE_CONDITIONAL);
        /** @var DummyValidator $v */
        $v = $vm->createValidator('DummyValidator', ['alpha'], [], ['name' => 'alphaV']);
        $req = $this->newWebRequest(['alpha' => 'A', 'beta' => 'B']);
        $this->assertTrue($vm->execute($req));
        // Get the pruned request from context after validation
        $req = $this->getContext()->getRequest();
        $this->assertTrue($req->hasParameter('alpha'));
        $this->assertFalse($req->hasParameter('beta'), 'Unvalidated parameter pruned when at least one validator present in conditional mode');
    }
}
