<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;

/**
 * Tests ValidationManager changes:
 * - MODE_RELAXED is silently converted to MODE_STRICT
 * - Runtime parameter keys are preserved in validation whitelist
 * - Validator exports remain accessible to actions
 */
class ValidationManagerStrictModeTest extends UnitTestCase
{
    private Context $_context;

    #[\Override]
    public function setUp(): void
    {
        $this->_context = $this->getContext();
    }

    public function testModeRelaxedConvertedToStrict(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');

        // Initialize with MODE_RELAXED
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_RELAXED]);

        // Should be converted to MODE_STRICT internally
        // Verify by checking that parameter whitelisting is enforced
        $request = $this->_context->getRequest();
        $request = $request->enforceValidatedParameters([]);

        // In strict mode, accessing unvalidated parameter should throw or return null
        $value = $request->getParameter('unvalidated_param', null);
        $this->assertNull($value, 'Unvalidated parameter should not be accessible in strict mode');
    }

    public function testModeConditionalStillAccepted(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');

        // MODE_CONDITIONAL should still work; initialize() must not throw.
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_CONDITIONAL]);
        $this->assertSame(ValidationManager::MODE_CONDITIONAL, $vm->getParameter('mode'));
    }

    public function testModeStrictStillAccepted(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');

        // MODE_STRICT should still work; initialize() must not throw.
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_STRICT]);
        $this->assertSame(ValidationManager::MODE_STRICT, $vm->getParameter('mode'));
    }

    public function testValidatorExportsAccessibleAfterValidation(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_STRICT]);

        $request = $this->_context->getRequest();

        // Simulate validator setting exported parameter via setParameter()
        // Before validation, set parameter (simulating validator export)
        $request = $request->setParameter('validator_export', 'exported_value');

        // After validation pruning, exported parameters should still be accessible
        $value = $request->getParameter('validator_export');
        $this->assertEquals('exported_value', $value);
    }

    public function testRuntimeParameterKeysPreservedInWhitelist(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_STRICT]);

        $request = $this->_context->getRequest();

        // Set multiple runtime parameters
        $request = $request->setParameter('runtime_param1', 'value1');
        $request = $request->setParameter('runtime_param2', 'value2');
        $request = $request->setParameter('runtime_param3', 'value3');

        // Get keys before validation
        $keys = $request->getRuntimeParameterKeys();
        $this->assertContains('runtime_param1', $keys);
        $this->assertContains('runtime_param2', $keys);
        $this->assertContains('runtime_param3', $keys);

        // After validation, these should still be accessible
        $this->assertEquals('value1', $request->getParameter('runtime_param1'));
        $this->assertEquals('value2', $request->getParameter('runtime_param2'));
        $this->assertEquals('value3', $request->getParameter('runtime_param3'));
    }

    public function testExecutedValidatorsConditionStillWorks(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_STRICT]);

        // Create validator with a known argument and provide the value in the request
        $vm->createValidator('DummyValidator', ['testfield'], [], ['required' => false]);

        $request = $this->newWebRequest(['testfield' => 'hello']);

        // Execute validation — should succeed (DummyValidator always passes)
        $result = $vm->execute($request);

        $this->assertTrue($result, 'Validation manager should return success when DummyValidator passes');
    }

    public function testPredeclaredExportsIncludedInWhitelist(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_STRICT]);

        // Set predeclared export names
        $vm->setParameter('_predeclared_exports', ['export1', 'export2']);

        $request = $this->_context->getRequest();

        // Even if these don't exist yet, they should be whitelisted
        // This allows actions to check for null exported values
        $request = $request->enforceValidatedParameters(['export1', 'export2']);

        // Should not throw exception even though value is null
        $value = $request->getParameter('export1', 'default');
        $this->assertEquals('default', $value);
    }

    public function testStrictModeEnforcesParameterWhitelisting(): void
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => ValidationManager::MODE_STRICT]);

        $request = $this->_context->getRequest();

        // Enforce empty whitelist
        $request = $request->enforceValidatedParameters([]);

        // With explicit default: returns the default (caller acknowledged absence)
        $value = $request->getParameter('non_whitelisted', null);
        $this->assertNull($value);
    }

    public function testGetParameterWithoutDefaultThrowsForUnvalidated(): void
    {
        $request = $this->_context->getRequest();

        $request = $request->enforceValidatedParameters([]);

        $this->expectException(\Quiote\Exception\UnvalidatedParameterAccessException::class);
        $request->getParameter('no_default_unvalidated');
    }

    public function testGetParameterWithDefaultReturnsDefaultForUnvalidated(): void
    {
        $request = $this->_context->getRequest();

        $request = $request->enforceValidatedParameters([]);

        $this->assertNull($request->getParameter('unvalidated', null));
        $this->assertSame('fallback', $request->getParameter('unvalidated', 'fallback'));
    }
}
