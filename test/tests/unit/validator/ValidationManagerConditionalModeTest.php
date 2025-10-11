<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Validator\AgaviValidator;

/**
 * Focused tests for MODE_CONDITIONAL pruning semantics.
 */
class ValidationManagerConditionalModeTest extends AgaviUnitTestCase
{
    private function newVm(string $mode): AgaviValidationManager
    {
        /** @var AgaviValidationManager $vm */
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->setParameter('mode', $mode);
        return $vm;
    }

    public function testZeroValidatorsKeepsParametersInConditionalMode(): void
    {
        $vm = $this->newVm(AgaviValidationManager::MODE_CONDITIONAL);
        $req = $this->newWebRequest(['keep' => 'v','other' => 'x']);
        $this->assertTrue($vm->execute($req));
        $this->assertTrue($req->hasParameter('keep'));
        $this->assertTrue($req->hasParameter('other'));
    }

    public function testWithValidatorsPrunesUnvalidatedParameters(): void
    {
        $vm = $this->newVm(AgaviValidationManager::MODE_CONDITIONAL);
        /** @var DummyValidator $v */
        $v = $vm->createValidator('DummyValidator', ['alpha'], [], ['name' => 'alphaV']);
        $req = $this->newWebRequest(['alpha' => 'A', 'beta' => 'B']);
        $this->assertTrue($vm->execute($req));
        $this->assertTrue($req->hasParameter('alpha'));
        $this->assertFalse($req->hasParameter('beta'), 'Unvalidated parameter pruned when at least one validator present in conditional mode');
    }
}
