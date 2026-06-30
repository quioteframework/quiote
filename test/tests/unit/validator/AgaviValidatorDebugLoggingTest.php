<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Util\DebugFlags;
use Agavi\Validator\AgaviValidator;

/**
 * Tests the improved debug logging in AgaviValidator::getData()
 * (the match expression replacing var_export for type-safe debug strings).
 * Verifies that data retrieval still works correctly with all value types
 * when debug logging is enabled.
 */
class AgaviValidatorDebugLoggingTest extends AgaviUnitTestCase
{
    #[\Override]
    public function tearDown(): void
    {
        DebugFlags::$validation = false;
    }

    private function runValidator(string $class, mixed $value, array $params = []): array
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator($class, ['value'], [], $params);
        $rd = $this->newWebRequest(['value' => $value]);
        return ['result' => $validator->execute($rd), 'vm' => $vm, 'rd' => $rd];
    }

    public function testScalarStringWithDebugEnabled()
    {
        DebugFlags::$validation = true;
        $res = $this->runValidator('DummyValidator', 'hello_world');
        $this->assertSame(AgaviValidator::SUCCESS, $res['result']);
    }

    public function testScalarStringWithDebugDisabled()
    {
        DebugFlags::$validation = false;
        $res = $this->runValidator('DummyValidator', 'some_string');
        $this->assertSame(AgaviValidator::SUCCESS, $res['result']);
    }

    public function testIntegerWithDebugEnabled()
    {
        DebugFlags::$validation = true;
        $res = $this->runValidator('DummyValidator', 42);
        $this->assertSame(AgaviValidator::SUCCESS, $res['result']);
    }

    public function testBoolTrueWithDebugEnabled()
    {
        DebugFlags::$validation = true;
        $res = $this->runValidator('DummyValidator', true);
        $this->assertSame(AgaviValidator::SUCCESS, $res['result']);
    }

    public function testValidatorFailureWithDebugEnabled()
    {
        DebugFlags::$validation = true;
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator('DummyValidator', ['value'], ['error' => 'bad']);
        $validator->val_result = false;
        $rd = $this->newWebRequest(['value' => 'test']);
        $result = $validator->execute($rd);
        // Failure should return an error severity, not SUCCESS
        $this->assertNotSame(AgaviValidator::SUCCESS, $result);
    }

    public function testArrayValueWithDebugEnabled()
    {
        DebugFlags::$validation = true;
        $res = $this->runValidator('DummyValidator', ['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertSame(AgaviValidator::SUCCESS, $res['result']);
    }

    public function testNonEmptyStringWithDebugEnabled()
    {
        DebugFlags::$validation = true;
        $res = $this->runValidator('DummyValidator', 'non-empty');
        $this->assertSame(AgaviValidator::SUCCESS, $res['result']);
    }

    public function testDebugFlagOnAndOffGiveSameResult()
    {
        DebugFlags::$validation = true;
        $resOn = $this->runValidator('DummyValidator', 'test_value');

        DebugFlags::$validation = false;
        $resOff = $this->runValidator('DummyValidator', 'test_value');

        $this->assertSame($resOn['result'], $resOff['result'],
            'Debug flag must not change validator result');
    }
}
