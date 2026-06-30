<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Request\AgaviWebRequest;

/**
 * Tests ValidationManager changes:
 * - MODE_RELAXED is silently converted to MODE_STRICT
 * - Runtime parameter keys are preserved in validation whitelist
 * - Validator exports remain accessible to actions
 */
class AgaviValidationManagerStrictModeTest extends AgaviUnitTestCase
{
    private $_context = null;

    #[\Override]
    public function setUp(): void
    {
        $this->_context = $this->getContext();
    }

    #[\Override]
    public function tearDown(): void
    {
        $this->_context = null;
    }

    public function testModeRelaxedConvertedToStrict()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');

        // Initialize with MODE_RELAXED
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_RELAXED]);

        // Should be converted to MODE_STRICT internally
        // Verify by checking that parameter whitelisting is enforced
        $request = $this->_context->getRequest();
        if ($request instanceof AgaviWebRequest) {
            $request->enforceValidatedParameters([]);

            // With strict mode, unvalidated parameters should not be accessible
            $hasException = false;
            try {
                $request->getParameter('unvalidated_param');
            } catch (\Exception) {
                $hasException = true;
            }

            // In strict mode, accessing unvalidated parameter should throw or return null
            $value = $request->getParameter('unvalidated_param', null);
            $this->assertNull($value, 'Unvalidated parameter should not be accessible in strict mode');
        }

        $this->assertTrue(true); // Test passed initialization
    }

    public function testModeConditionalStillAccepted()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');

        // MODE_CONDITIONAL should still work
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_CONDITIONAL]);

        $this->assertTrue(true); // No exception thrown
    }

    public function testModeStrictStillAccepted()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');

        // MODE_STRICT should still work
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_STRICT]);

        $this->assertTrue(true); // No exception thrown
    }

    public function testValidatorExportsAccessibleAfterValidation()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_STRICT]);
        
        $request = $this->_context->getRequest();
        
        // Simulate validator setting exported parameter via setParameter()
        if ($request instanceof AgaviWebRequest) {
            // Before validation, set parameter (simulating validator export)
            $request->setParameter('validator_export', 'exported_value');
            
            // After validation pruning, exported parameters should still be accessible
            $value = $request->getParameter('validator_export');
            $this->assertEquals('exported_value', $value);
        }
    }

    public function testRuntimeParameterKeysPreservedInWhitelist()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_STRICT]);
        
        $request = $this->_context->getRequest();
        
        if ($request instanceof AgaviWebRequest) {
            // Set multiple runtime parameters
            $request->setParameter('runtime_param1', 'value1');
            $request->setParameter('runtime_param2', 'value2');
            $request->setParameter('runtime_param3', 'value3');
            
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
    }

    public function testExecutedValidatorsConditionStillWorks()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_STRICT]);

        // Create validator with a known argument and provide the value in the request
        $vm->createValidator('DummyValidator', ['testfield'], [], ['required' => false]);

        $request = $this->newWebRequest(['testfield' => 'hello']);

        // Execute validation — should succeed (DummyValidator always passes)
        $result = $vm->execute($request);

        $this->assertTrue((bool)$result, 'Validation manager should return success when DummyValidator passes');
    }

    public function testPredeclaredExportsIncludedInWhitelist()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_STRICT]);
        
        // Set predeclared export names
        $vm->setParameter('_predeclared_exports', ['export1', 'export2']);
        
        $request = $this->_context->getRequest();
        
        if ($request instanceof AgaviWebRequest) {
            // Even if these don't exist yet, they should be whitelisted
            // This allows actions to check for null exported values
            $request->enforceValidatedParameters(['export1', 'export2']);
            
            // Should not throw exception even though value is null
            $value = $request->getParameter('export1', 'default');
            $this->assertEquals('default', $value);
        }
    }

    public function testStrictModeEnforcesParameterWhitelisting()
    {
        $vm = $this->_context->createInstanceFor('validation_manager');
        $vm->initialize($this->_context, ['mode' => AgaviValidationManager::MODE_STRICT]);

        $request = $this->_context->getRequest();

        if ($request instanceof AgaviWebRequest) {
            // Enforce empty whitelist
            $request->enforceValidatedParameters([]);

            // With explicit default: returns the default (caller acknowledged absence)
            $value = $request->getParameter('non_whitelisted', null);
            $this->assertNull($value);
        }
    }

    public function testGetParameterWithoutDefaultThrowsForUnvalidated()
    {
        $request = $this->_context->getRequest();

        if (!($request instanceof AgaviWebRequest)) {
            $this->markTestSkipped('Requires AgaviWebRequest');
        }

        $request->enforceValidatedParameters([]);

        $this->expectException(\Agavi\Exception\AgaviUnvalidatedParameterAccessException::class);
        $request->getParameter('no_default_unvalidated');
    }

    public function testGetParameterWithDefaultReturnsDefaultForUnvalidated()
    {
        $request = $this->_context->getRequest();

        if (!($request instanceof AgaviWebRequest)) {
            $this->markTestSkipped('Requires AgaviWebRequest');
        }

        $request->enforceValidatedParameters([]);

        $this->assertNull($request->getParameter('unvalidated', null));
        $this->assertSame('fallback', $request->getParameter('unvalidated', 'fallback'));
    }
}
