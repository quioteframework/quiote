<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviValidationManager;

/**
 * Tests that duplicate validator names are overwritten (not exception) in test env.
 */
class ValidationManagerDuplicateNameTest extends AgaviUnitTestCase
{
    public function testDuplicateNameOverwrite(): void
    {
        if (!defined('AGAVI_TESTING')) {
            $this->markTestSkipped('Requires AGAVI_TESTING environment');
        }
        /** @var AgaviValidationManager $vm */
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        /** @var DummyValidator $v1 */
        $v1 = $vm->createValidator('DummyValidator', [], [], ['name' => 'dup']);
        $v1->val_result = true;
        /** @var DummyValidator $v2 */
        $v2 = $vm->createValidator('DummyValidator', [], [], ['name' => 'dup']);
        $v2->val_result = false; // ensure second one used
        $req = $this->newWebRequest();
        $this->assertFalse($vm->execute($req), 'Second validator replaced first');
    }
}
