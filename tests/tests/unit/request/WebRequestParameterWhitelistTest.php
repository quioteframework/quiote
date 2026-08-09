<?php

use PHPUnit\Framework\TestCase;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;

/**
 * Tests the new auto-whitelist functionality for setParameter() and getRuntimeParameterKeys()
 * added to WebRequest to support strict validation mode.
 */
class WebRequestParameterWhitelistTest extends UnitTestCase
{
    private WebRequest $request;

    #[\Override]
    public function setUp(): void
    {
        $this->request = new WebRequest();
        $context = $this->getContext();
        $this->request->initialize($context);
    }

    public function testGetRuntimeParameterKeysReturnsAllKeys(): void
    {
        $this->request = $this->request->setParameter('foo', 'bar');
        $this->request = $this->request->setParameter('baz', 'qux');
        $this->request = $this->request->setParameter('num', 123);
        
        $keys = $this->request->getRuntimeParameterKeys();

        $this->assertContains('foo', $keys);
        $this->assertContains('baz', $keys);
        $this->assertContains('num', $keys);
    }

    public function testGetRuntimeParameterKeysReturnsEmptyArrayInitially(): void
    {
        $keys = $this->request->getRuntimeParameterKeys();

        $this->assertEmpty($keys);
    }

    public function testSetParameterAutoWhitelistsParameter(): void
    {
        // Enable strict validation
        $this->request = $this->request->enforceValidatedParameters([]);

        // Set a parameter - should be auto-whitelisted
        $this->request = $this->request->setParameter('autowhitelisted', 'value');
        
        // Should be accessible without exception
        $value = $this->request->getParameter('autowhitelisted');
        $this->assertEquals('value', $value);
    }

    public function testSetParameterAutoWhitelistsArrayParameter(): void
    {
        $this->request = $this->request->enforceValidatedParameters([]);

        $arrayData = ['key1' => 'val1', 'key2' => 'val2'];
        $this->request = $this->request->setParameter('arrayParam', $arrayData);
        
        $retrieved = $this->request->getParameter('arrayParam');
        $this->assertEquals($arrayData, $retrieved);
    }

    public function testSetParameterWithBracketPathAutoWhitelists(): void
    {
        $this->request = $this->request->enforceValidatedParameters([]);

        // Set nested array data
        $this->request = $this->request->setParameter('data', [
            ['field1' => 'value1', 'field2' => 'value2']
        ]);
        
        // Should be accessible
        $data = $this->request->getParameter('data');
        $this->assertIsArray($data);
        $this->assertIsArray($data[0]);
        $this->assertEquals('value1', $data[0]['field1']);
        
        // Root should be whitelisted
        $keys = $this->request->getRuntimeParameterKeys();
        $this->assertContains('data', $keys);
    }

    public function testMultipleSetParameterCallsAllWhitelisted(): void
    {
        $this->request = $this->request->enforceValidatedParameters([]);

        $this->request = $this->request->setParameter('param1', 'val1');
        $this->request = $this->request->setParameter('param2', 'val2');
        $this->request = $this->request->setParameter('param3', 'val3');
        
        $this->assertEquals('val1', $this->request->getParameter('param1'));
        $this->assertEquals('val2', $this->request->getParameter('param2'));
        $this->assertEquals('val3', $this->request->getParameter('param3'));
        
        $keys = $this->request->getRuntimeParameterKeys();
        $this->assertContains('param1', $keys);
        $this->assertContains('param2', $keys);
        $this->assertContains('param3', $keys);
    }

    public function testSetParameterInValidationExportScenario(): void
    {
        // Simulate validator export scenario
        $this->request = $this->request->enforceValidatedParameters(['input']);

        // Validator would call setParameter to export processed data
        $this->request = $this->request->setParameter('exported_data', 'processed_value');
        
        // Action should be able to access exported data
        $value = $this->request->getParameter('exported_data');
        $this->assertEquals('processed_value', $value);
    }

    public function testRuntimeParameterKeysIncludesAllSetParameters(): void
    {
        $this->request = $this->request->setParameter('p1', 'v1');
        $this->request = $this->request->setParameter('p2', 'v2');
        $this->request = $this->request->setParameter('nested', ['a' => 1, 'b' => 2]);
        
        $keys = $this->request->getRuntimeParameterKeys();
        
        $this->assertGreaterThanOrEqual(3, count($keys));
        $this->assertContains('p1', $keys);
        $this->assertContains('p2', $keys);
        $this->assertContains('nested', $keys);
    }

    public function testSetParameterOverwritesBehavior(): void
    {
        $this->request = $this->request->setParameter('key', 'original');
        $this->request = $this->request->setParameter('key', 'updated');
        
        $value = $this->request->getParameter('key');
        $this->assertEquals('updated', $value);
        
        $keys = $this->request->getRuntimeParameterKeys();
        // Key should appear once
        $countKey = 0;
        foreach ($keys as $k) {
            if ($k === 'key') $countKey++;
        }
        $this->assertEquals(1, $countKey);
    }

    public function testRemoveParameterDropsTheValueButKeepsTheNameDeclared(): void
    {
        $this->request = $this->request->setParameter('kept', 'value');

        $this->request = $this->request->removeParameter('kept');

        // The declaration survives, so reading it answers the default instead of refusing.
        $this->assertNull($this->request->getParameter('kept'));
    }

    public function testRevokeParameterUndeclaresTheNameSoReadingItRefuses(): void
    {
        $this->request = $this->request->setParameter('revoked', 'value');
        $this->assertSame('value', $this->request->getParameter('revoked'));

        $this->request = $this->request->revokeParameter('revoked');

        $this->assertFalse($this->request->hasParameter('revoked'));
        $this->expectException(\Quiote\Exception\UnvalidatedParameterAccessException::class);
        $this->request->getParameter('revoked');
    }

    public function testRevokeParameterLeavesOtherParametersDeclared(): void
    {
        $this->request = $this->request->setParameter('revoked', 'gone');
        $this->request = $this->request->setParameter('kept', 'still-here');

        $this->request = $this->request->revokeParameter('revoked');

        $this->assertSame('still-here', $this->request->getParameter('kept'));
    }

    public function testRevokeParameterOnAnUnknownNameIsANoOp(): void
    {
        $this->request = $this->request->setParameter('kept', 'value');

        $this->request = $this->request->revokeParameter('neverSet');

        $this->assertSame('value', $this->request->getParameter('kept'));
        $this->assertFalse($this->request->hasParameter('neverSet'));
    }

    public function testRevokeParameterReturnsANewInstanceAndLeavesTheOriginalIntact(): void
    {
        $original = $this->request->setParameter('revoked', 'value');

        $revoked = $original->revokeParameter('revoked');

        $this->assertNotSame($original, $revoked);
        $this->assertSame('value', $original->getParameter('revoked'), 'WebRequest is immutable');
    }
}
