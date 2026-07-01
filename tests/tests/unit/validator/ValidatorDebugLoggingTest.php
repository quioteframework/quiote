<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Logging\Log;
use Quiote\Logging\Level;
use Quiote\Logging\Sink\JsonStdoutSink;
use Quiote\Validator\Validator;

/**
 * Tests the type-safe debug string building in Validator::getData()
 * (the match expression replacing var_export). Verifies data retrieval still
 * works for all value types when debug logging is ENABLED — i.e. that the
 * isEnabled(Debug)-guarded debug paths in the validator don't affect results
 * or throw.
 * Debug is now toggled via the PSR-3 Log facade (category level + a sink) rather
 * than the removed QUIOTE_DEBUG_* / DebugFlags mechanism. A php://memory sink
 * keeps debug output out of the test's stdout.
 */
class ValidatorDebugLoggingTest extends UnitTestCase
{
    /** Enable DEBUG-level logging into a throwaway in-memory sink. */
    private function enableDebug(): void
    {
        Log::reset();
        Log::setDefaultLevel(Level::Debug);
        Log::addSink(new JsonStdoutSink(Level::Debug, [], 'php://stdout', fopen('php://memory', 'r+')));
    }

    #[\Override]
    public function tearDown(): void
    {
        Log::reset();
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
        $this->enableDebug();
        $res = $this->runValidator('DummyValidator', 'hello_world');
        $this->assertSame(Validator::SUCCESS, $res['result']);
    }

    public function testScalarStringWithDebugDisabled()
    {
        Log::reset();
        $res = $this->runValidator('DummyValidator', 'some_string');
        $this->assertSame(Validator::SUCCESS, $res['result']);
    }

    public function testIntegerWithDebugEnabled()
    {
        $this->enableDebug();
        $res = $this->runValidator('DummyValidator', 42);
        $this->assertSame(Validator::SUCCESS, $res['result']);
    }

    public function testBoolTrueWithDebugEnabled()
    {
        $this->enableDebug();
        $res = $this->runValidator('DummyValidator', true);
        $this->assertSame(Validator::SUCCESS, $res['result']);
    }

    public function testValidatorFailureWithDebugEnabled()
    {
        $this->enableDebug();
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator('DummyValidator', ['value'], ['error' => 'bad']);
        $validator->val_result = false;
        $rd = $this->newWebRequest(['value' => 'test']);
        $result = $validator->execute($rd);
        // Failure should return an error severity, not SUCCESS
        $this->assertNotSame(Validator::SUCCESS, $result);
    }

    public function testArrayValueWithDebugEnabled()
    {
        $this->enableDebug();
        $res = $this->runValidator('DummyValidator', ['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertSame(Validator::SUCCESS, $res['result']);
    }

    public function testNonEmptyStringWithDebugEnabled()
    {
        $this->enableDebug();
        $res = $this->runValidator('DummyValidator', 'non-empty');
        $this->assertSame(Validator::SUCCESS, $res['result']);
    }

    public function testDebugOnAndOffGiveSameResult()
    {
        $this->enableDebug();
        $resOn = $this->runValidator('DummyValidator', 'test_value');

        Log::reset();
        $resOff = $this->runValidator('DummyValidator', 'test_value');

        $this->assertSame($resOn['result'], $resOff['result'],
            'Debug logging must not change validator result');
    }
}
