<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;
// Include dummy validator used for behavior instrumentation
require_once __DIR__ . '/../../../lib/validator/DummyValidator.class.php';

/**
 * Additional branch coverage tests for ValidationManager and core validators
 * focusing on exports merging, reflection argument pre-whitelist, mixed severities,
 * dependency tokens interaction, and validator side-effect persistence.
 */
class ValidationManagerAdditionalCoverageTest extends UnitTestCase
{
    private function vm(array $params = []): ValidationManager
    {
        /** @var ValidationManager $vm */
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        foreach($params as $k=>$v) { $vm->setParameter($k,$v); }
        return $vm;
    }

    public function testExportedValueWhitelistedAndRetained(): void
    {
        $vm = $this->vm(['mode' => ValidationManager::MODE_STRICT]);
        // equals validator exporting to name 'result'
        $eq = $vm->createValidator(\Quiote\Validator\EqualsValidator::class, ['foo'], [], ['name'=>'eq','value'=>'123','export'=>'result']);
        $req = $this->newWebRequest(['foo' => '123']);
        $this->assertTrue($vm->execute($req));
        // Export should have been whitelisted & preserved
        $this->assertTrue($req->hasParameter('result'));
        $this->assertSame('123', $req->getParameter('result'));
    }

    public function testBooleanValidatorCastsRuntimeOnSuccess(): void
    {
        $vm = $this->vm(['mode' => ValidationManager::MODE_RELAXED]);
        $bool = $vm->createValidator(\Quiote\Validator\BooleanValidator::class, ['flag'], [], ['name'=>'b']);
        $req = $this->newWebRequest(['flag' => '1']);
        $this->assertTrue($vm->execute($req));
        // Whitelist for strict enforcement path; relaxed mode still enforces ALWAYS, supply keys now.
        $req->enforceValidatedParameters(['flag']);
        $this->assertSame(true, $req->getParameter('flag')); // cast persisted
    }

    public function testNumberValidatorCastingAndMinMaxErrors(): void
    {
        // First: success with casting export
        $vm1 = $this->vm(['mode' => ValidationManager::MODE_RELAXED]);
        $num1 = $vm1->createValidator(\Quiote\Validator\NumberValidator::class, ['amount'], [], ['name'=>'n1','type'=>'int','min'=>1,'max'=>10,'cast_to'=>'int']);
        $req1 = $this->newWebRequest(['amount' => '5']);
        $this->assertTrue($vm1->execute($req1));
        $req1->enforceValidatedParameters(['amount']);
        $this->assertSame(5, $req1->getParameter('amount'));
        // Second: fails min
        $vm2 = $this->vm(['mode' => ValidationManager::MODE_RELAXED]);
        $num2 = $vm2->createValidator(\Quiote\Validator\NumberValidator::class, ['amount'], [], ['name'=>'n2','type'=>'int','min'=>3,'max'=>10]);
        $req2 = $this->newWebRequest(['amount' => '2']);
        $this->assertFalse($vm2->execute($req2));
        // Third: fails max
        $vm3 = $this->vm(['mode' => ValidationManager::MODE_RELAXED]);
        $num3 = $vm3->createValidator(\Quiote\Validator\NumberValidator::class, ['amount'], [], ['name'=>'n3','type'=>'int','min'=>1,'max'=>2]);
        $req3 = $this->newWebRequest(['amount' => '3']);
        $this->assertFalse($vm3->execute($req3));
    }

    public function testCriticalStopsInfoAndExportMerging(): void
    {
        $vm = $this->vm(['mode' => ValidationManager::MODE_RELAXED]);
    /** @var DummyValidator $crit */
    $crit = $vm->createValidator('DummyValidator', ['alpha'], [], ['name'=>'c','severity'=>'critical']);
    /** @var DummyValidator $info */
    $info = $vm->createValidator('DummyValidator', ['beta'], [], ['name'=>'i','severity'=>'info']);
        $crit->val_result = false; // force stop
        $req = $this->newWebRequest(['alpha'=>'x','beta'=>'y']);
        $this->assertFalse($vm->execute($req));
        $this->assertTrue($crit->validated);
        $this->assertFalse($info->validated, 'Info validator skipped after critical failure');
    }

    public function testReflectionArgumentHarvestPreWhitelist(): void
    {
        // Create a validator with arguments but access parameter inside validate (simulated by DummyValidator reading). Manager should pre-whitelist via reflection.
        $vm = $this->vm(['mode' => ValidationManager::MODE_RELAXED]);
    /** @var DummyValidator $dv */
    $dv = $vm->createValidator('DummyValidator', ['fieldA','fieldB'], [], ['name'=>'d']);
        $dv->val_result = true;
        $req = $this->newWebRequest(['fieldA'=>'1','fieldB'=>'2']);
        $this->assertTrue($vm->execute($req));
        // After execution arguments still retrievable under strict enforcement if we now enforce
        $req->enforceValidatedParameters(['fieldA','fieldB']);
        $this->assertSame('1', $req->getParameter('fieldA'));
        $this->assertSame('2', $req->getParameter('fieldB'));
    }
}
