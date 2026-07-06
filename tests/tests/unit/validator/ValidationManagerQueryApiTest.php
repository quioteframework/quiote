<?php

use Quiote\Validator\Validator;
use Quiote\Util\VirtualArrayPath;

/**
 * Happy + failure path coverage for ValidationManager's deprecated result-query
 * API (error/hasError lookups, getIncidents, getFailedFields, setError(s), reset),
 * which previously had almost no direct test coverage of its own.
 */
class AlwaysPassQMValidator extends Validator
{
    #[\Override]
    protected function validate()
    {
        return true;
    }
}

class AlwaysFailQMValidator extends Validator
{
    #[\Override]
    protected function validate()
    {
        // Real validators call throwError() themselves on failure to record an
        // incident (and its error message) -- simply returning false only sets
        // the argument's severity, it does not create an incident.
        $this->throwError();
        return false;
    }
}

/**
 * Every test here deliberately exercises ValidationManager's
 * #[\Deprecated]-marked legacy query API (getError(), hasErrors(), setError(),
 * etc.) -- that's the entire point of this file, since those methods are
 * still shipped and used but previously had almost no coverage. Ignoring
 * deprecations at the class level suppresses that expected noise without
 * hiding genuine accidental deprecated-API usage elsewhere in the suite.
 */
#[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
class ValidationManagerQueryApiTest extends BaseValidatorTest
{
    public function testGetChildAndGetChilds(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $passer = $vm->createValidator(AlwaysPassQMValidator::class, ['field_ok'], [], ['name' => 'passer']);

        $this->assertSame($passer, $vm->getChild('passer'));
        $this->assertCount(1, $vm->getChilds());

        $this->expectException(InvalidArgumentException::class);
        $vm->getChild('missing');
    }

    public function testAddChildRejectsDuplicateNameOutsideTesting(): void
    {
        // See ValidationManagerDuplicateNameTest for the complementary QUIOTE_TESTING-defined
        // case; that constant, once defined anywhere in the shared test process, can never be
        // undefined again, so this scenario can only be exercised while it's still unset.
        if (defined('QUIOTE_TESTING')) {
            $this->markTestSkipped('QUIOTE_TESTING is already defined in this process.');
        }

        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->createValidator(AlwaysPassQMValidator::class, ['a'], [], ['name' => 'dup']);

        $this->expectException(InvalidArgumentException::class);
        $vm->createValidator(AlwaysPassQMValidator::class, ['b'], [], ['name' => 'dup']);
    }

    /** @return array{0: \Quiote\Validator\ValidationManager, 1: \Quiote\Request\WebRequest} */
    private function buildMixedResultManager(): array
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $pass = $vm->createValidator(AlwaysPassQMValidator::class, ['field_ok'], [], [
            'name' => 'passer',
            'severity' => 'notice',
        ]);
        $fail = $vm->createValidator(AlwaysFailQMValidator::class, ['field_bad'], ['' => 'Bad value!'], [
            'name' => 'failer',
            'severity' => 'error',
        ]);

        $rd = $this->newWebRequest(['field_ok' => 'x', 'field_bad' => 'y']);
        $pass->execute($rd);
        $fail->execute($rd);

        return [$vm, $rd];
    }

    public function testResultQueriesOnMixedSuccessAndFailure(): void
    {
        [$vm] = $this->buildMixedResultManager();

        $this->assertSame(Validator::ERROR, $vm->getResult());
        $this->assertTrue($vm->hasErrors());

        $this->assertTrue($vm->isFieldFailed('field_bad'));
        $this->assertFalse($vm->isFieldFailed('field_ok'));

        $this->assertTrue($vm->isFieldValidated('field_ok'));
        $this->assertTrue($vm->isFieldValidated('field_bad'));

        $this->assertSame(Validator::ERROR, $vm->getFieldErrorCode('field_bad'));
        // A passing validator always records SUCCESS regardless of its configured
        // 'severity' parameter -- that parameter only picks the error code used on failure.
        $this->assertSame(Validator::SUCCESS, $vm->getFieldErrorCode('field_ok'));

        $this->assertContains('field_ok', $vm->getSucceededFields('parameters'));
        $this->assertNotContains('field_bad', $vm->getSucceededFields('parameters'));
    }

    public function testIncidentQueriesOnMixedSuccessAndFailure(): void
    {
        [$vm] = $this->buildMixedResultManager();

        $this->assertTrue($vm->hasIncidents());
        $this->assertFalse($vm->hasIncidents(Validator::CRITICAL));

        $this->assertNotEmpty($vm->getIncidents());
        $this->assertEmpty($vm->getIncidents(Validator::CRITICAL));

        // The passing validator never calls throwError(), so it never records an incident.
        $this->assertEmpty($vm->getValidatorIncidents('passer'));
        $this->assertNotEmpty($vm->getValidatorIncidents('failer'));

        $this->assertEmpty($vm->getFieldIncidents('field_ok'));
        $this->assertNotEmpty($vm->getFieldIncidents('field_bad'));

        $this->assertNotEmpty($vm->getFieldErrors('field_bad'));
        $this->assertEmpty($vm->getFieldErrors('field_ok'));

        $this->assertNotEmpty($vm->getValidatorFieldErrors('failer', 'field_bad'));
        $this->assertEmpty($vm->getValidatorFieldErrors('passer', 'field_bad'));

        $this->assertContains('field_bad', $vm->getFailedFields());
        $this->assertNotContains('field_ok', $vm->getFailedFields());
    }

    public function testErrorAccessorsOnMixedSuccessAndFailure(): void
    {
        [$vm] = $this->buildMixedResultManager();

        $this->assertSame('Bad value!', $vm->getError('field_bad'));
        $this->assertNull($vm->getError('field_ok'));
        $this->assertNull($vm->getError('nonexistent_field'));

        $this->assertContains('field_bad', $vm->getErrorNames());

        $errors = $vm->getErrors();
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('field_bad', $errors);
        $fieldBadErrors = $errors['field_bad'];
        $this->assertIsArray($fieldBadErrors);
        $this->assertSame(['Bad value!'], $fieldBadErrors['messages']);
        $this->assertSame(['failer'], $fieldBadErrors['validators']);
        $this->assertNull($vm->getErrors('nonexistent_field'));
        $this->assertSame($fieldBadErrors, $vm->getErrors('field_bad'));

        $this->assertSame(['Bad value!'], $vm->getErrorMessages('field_bad'));
        $this->assertSame([], $vm->getErrorMessages('field_ok'));
        $allMessages = $vm->getErrorMessages();
        $this->assertCount(1, $allMessages);
        $firstMessage = $allMessages[0];
        $this->assertIsArray($firstMessage);
        $this->assertSame('Bad value!', $firstMessage['message']);

        $this->assertTrue($vm->hasError('field_bad'));
        $this->assertFalse($vm->hasError('field_ok'));
    }

    public function testHappyPathHasNoErrors(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $pass = $vm->createValidator(AlwaysPassQMValidator::class, ['field_ok'], [], ['name' => 'passer']);
        $rd = $this->newWebRequest(['field_ok' => 'x']);
        $pass->execute($rd);

        $this->assertFalse($vm->hasErrors());
        $this->assertFalse($vm->hasError('field_ok'));
        $this->assertSame([], $vm->getFailedFields());
        $this->assertFalse($vm->hasIncidents());
    }

    public function testSetErrorManuallyInjectsAnIncident(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->setError('manual_field', 'Something went wrong manually.');

        $this->assertTrue($vm->hasError('manual_field'));
        $this->assertSame('Something went wrong manually.', $vm->getError('manual_field'));
        $this->assertTrue($vm->hasErrors());
    }

    public function testSetErrorsInjectsErrorsForAllGivenFields(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $vm->setErrors([
            'field_a' => 'Error A',
            'field_b' => 'Error B',
        ]);

        // setErrors() batches every field into a single incident; getError() only
        // ever returns that incident's first error regardless of which field is
        // asked about -- a known quirk of this deprecated API. hasError() and
        // getErrorMessages() correctly reflect each field independently.
        $this->assertTrue($vm->hasError('field_a'));
        $this->assertTrue($vm->hasError('field_b'));
        $this->assertSame(['Error A', 'Error B'], $vm->getErrorMessages('field_a'));
        $this->assertSame(['Error A', 'Error B'], $vm->getErrorMessages('field_b'));
        $this->assertTrue($vm->hasErrors());
    }

    public function testResetClearsChildrenAndReport(): void
    {
        [$vm] = $this->buildMixedResultManager();
        $this->assertTrue($vm->hasErrors());
        $this->assertCount(2, $vm->getChilds());

        $vm->reset();

        $this->assertFalse($vm->hasErrors());
        $this->assertCount(0, $vm->getChilds());
    }

    public function testShutdownDelegatesToChildren(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator(SampleShutdownTrackingValidator::class, ['a'], [], ['name' => 'tracked']);
        if (!$validator instanceof SampleShutdownTrackingValidator) {
            throw new \RuntimeException('Expected a SampleShutdownTrackingValidator instance.');
        }

        $vm->shutdown();

        $this->assertTrue($validator->wasShutdown);
    }

    public function testGetDependencyManagerReturnsSameInstanceAsManager(): void
    {
        $vm = $this->getContext()->createInstanceFor('validation_manager');
        $validator = $vm->createValidator(AlwaysPassQMValidator::class, ['a'], [], ['name' => 'passer']);

        $this->assertSame($vm->getDependencyManager(), $validator->getDependencyManager());
    }
}

class SampleShutdownTrackingValidator extends Validator
{
    public bool $wasShutdown = false;

    #[\Override]
    protected function validate()
    {
        return true;
    }

    #[\Override]
    public function shutdown()
    {
        $this->wasShutdown = true;
    }
}
