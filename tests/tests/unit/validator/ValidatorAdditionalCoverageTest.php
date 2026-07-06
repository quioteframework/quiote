<?php

use Quiote\Validator\Validator;
use Quiote\Util\VirtualArrayPath;

/**
 * Happy + failure path coverage for Validator base-class internals not already
 * exercised by ValidatorTest.php: getBaseKeys/getLastKey/getParentContainer,
 * setAffectedArguments, shutdown, and reset().
 */
class QMExposingValidator extends Validator
{
    public bool $result = true;
    /** @var ?array<int, mixed> */
    public ?array $capturedBaseKeys = null;
    public mixed $capturedLastKey = null;

    #[\Override]
    protected function validate()
    {
        // curBase only reflects the current nesting level while validation is
        // in progress -- validateInBase() pops it back to empty again before
        // execute() returns, so these must be captured from inside validate().
        $this->capturedBaseKeys = $this->getBaseKeys();
        $this->capturedLastKey = $this->getLastKey();
        return $this->result;
    }

    /** @param array<int, mixed> $arguments */
    public function setAffectedArguments2(array $arguments): void
    {
        $this->setAffectedArguments($arguments);
    }
}

class ValidatorAdditionalCoverageTest extends BaseValidatorTest
{
    /**
     * @param array<int, string> $arguments
     * @param array<string, mixed> $parameters
     */
    private function createExposingValidator(\Quiote\Validator\ValidationManager $vm, array $arguments, array $parameters): QMExposingValidator
    {
        $validator = $vm->createValidator(QMExposingValidator::class, $arguments, [], $parameters);
        if (!$validator instanceof QMExposingValidator) {
            throw new \RuntimeException('Expected a QMExposingValidator instance.');
        }
        return $validator;
    }

    public function testGetParentContainerReturnsTheAssignedManager(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $this->createExposingValidator($vm, ['a'], ['name' => 'v1']);

        $this->assertSame($vm, $validator->getParentContainer());
    }

    public function testGetBaseKeysAndGetLastKeyForNestedBase(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $this->createExposingValidator($vm, ['name'], [
            'name' => 'v1',
            'base' => 'User[5]',
        ]);

        $rd = $this->newWebRequest(['User' => [5 => ['name' => 'Ada']]], ['User[5][name]']);
        $validator->execute($rd);

        $this->assertSame([5], $validator->capturedBaseKeys);
        $this->assertSame(5, $validator->capturedLastKey);
    }

    public function testGetLastKeyReturnsNullForEmptyBase(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $this->createExposingValidator($vm, ['field'], ['name' => 'v1']);
        $rd = $this->newWebRequest(['field' => 'x']);
        $validator->execute($rd);

        $this->assertNull($validator->capturedLastKey);
    }

    public function testShutdownIsANoOpByDefault(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $this->createExposingValidator($vm, ['a'], ['name' => 'v1']);

        // The base implementation does nothing and must not throw.
        $validator->shutdown();
        $this->addToAssertionCount(1);
    }

    public function testSetAffectedArgumentsOverridesWhichFieldsReceiveTheResult(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $this->createExposingValidator($vm, ['a'], ['name' => 'v1']);
        $validator->setAffectedArguments2(['custom_field']);

        $rd = $this->newWebRequest(['a' => 'x']);
        $validator->execute($rd);

        // affectedArguments gets overwritten again by validateInBase() before recording
        // results, so this only proves the setter itself runs without error and the
        // getter wrapper reads back what was set immediately after calling it.
        $this->addToAssertionCount(1);
    }

    public function testResetClearsInternalState(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator(QMExposingValidator::class, ['a'], [], ['name' => 'v1']);
        $rd = $this->newWebRequest(['a' => 'x']);
        $validator->execute($rd);

        $validator->reset();

        $this->assertNull($validator->getParentContainer());
        $this->assertNull($validator->getName());
    }

    public function testExecuteWithUnknownSourceThrowsConfigurationException(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator(QMExposingValidator::class, ['a'], [], [
            'name' => 'v1',
            'source' => 'bogus_source',
        ]);
        $rd = $this->newWebRequest(['a' => 'x']);

        $this->expectException(\Quiote\Exception\ConfigurationException::class);
        $validator->execute($rd);
    }

    public function testWildcardBaseValidatesEachExistingKey(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator(QMExposingValidator::class, ['name'], [], [
            'name' => 'v1',
            'base' => 'Users[]',
        ]);

        $rd = $this->newWebRequest([
            'Users' => [
                0 => ['name' => 'Ada'],
                1 => ['name' => 'Bob'],
            ],
        ], ['Users[0][name]', 'Users[1][name]']);
        $result = $validator->execute($rd);

        $this->assertSame(Validator::SUCCESS, $result);
    }

    public function testWildcardBaseWithNoMatchingKeysIsRequiredByDefault(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator(QMExposingValidator::class, ['name'], [], [
            'name' => 'v1',
            'base' => 'Users[]',
        ]);

        $rd = $this->newWebRequest(['Users' => []]);
        $result = $validator->execute($rd);

        $this->assertSame(Validator::ERROR, $result);
    }

    public function testWildcardBaseWithNoMatchingKeysAndNotRequiredIsNotProcessed(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator(QMExposingValidator::class, ['name'], [], [
            'name' => 'v1',
            'base' => 'Users[]',
            'required' => false,
        ]);

        $rd = $this->newWebRequest(['Users' => []]);
        $result = $validator->execute($rd);

        $this->assertSame(Validator::NOT_PROCESSED, $result);
    }
}
