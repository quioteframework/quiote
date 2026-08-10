<?php

declare(strict_types=1);

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ArraylengthValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

/**
 * ArraylengthValidator bounds how many elements an array parameter carries.
 *
 * It cannot use the ordinary "is the argument set" precheck, which treats an
 * empty value as absent: an empty array is a legitimate submission whose
 * length is nought, and a `min` of 1 is what should reject it -- not the
 * precheck, silently and with the wrong message. So it decides for itself
 * whether the argument is present *and an array*.
 */
final class ArraylengthValidatorTest extends UnitTestCase
{
    private ValidationManager $vm;

    #[\Override]
    public function setUp(): void
    {
        $this->vm = $this->getContext()->getContainer()->get(ValidationManager::class);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $validatorParameters
     */
    private function validate(array $parameters, array $validatorParameters = []): int
    {
        $validator = $this->vm->createValidator(
            ArraylengthValidator::class,
            ['tags'],
            ['' => 'wrong length', 'min' => 'too few', 'max' => 'too many', 'required' => 'tags missing'],
            $validatorParameters,
        );

        return $validator->execute($this->newWebRequest($parameters, ['tags']));
    }

    public function testMinAndMaxAreAcceptedParameters(): void
    {
        $accepted = ArraylengthValidator::getAcceptedParameters();

        $this->assertContains('min', $accepted);
        $this->assertContains('max', $accepted);
        $this->assertContains('required', $accepted, 'the base parameters are still accepted');
    }

    // --- bounds ------------------------------------------------------------

    public function testAnArrayWithinBothBoundsPasses(): void
    {
        $this->assertSame(
            Validator::SUCCESS,
            $this->validate(['tags' => ['a', 'b']], ['min' => 1, 'max' => 3]),
        );
    }

    public function testAnArrayShorterThanMinIsRejected(): void
    {
        $this->assertSame(Validator::ERROR, $this->validate(['tags' => ['a']], ['min' => 2]));
    }

    public function testAnArrayLongerThanMaxIsRejected(): void
    {
        $this->assertSame(Validator::ERROR, $this->validate(['tags' => ['a', 'b', 'c']], ['max' => 2]));
    }

    /** The bounds are inclusive, so a length exactly at either one passes. */
    public function testTheBoundsAreInclusive(): void
    {
        $this->assertSame(Validator::SUCCESS, $this->validate(['tags' => ['a', 'b']], ['min' => 2]));
        $this->assertSame(Validator::SUCCESS, $this->validate(['tags' => ['a', 'b']], ['max' => 2]));
    }

    /** Each bound is optional and only checked when configured. */
    public function testAnUnboundedValidatorAcceptsAnyArray(): void
    {
        $this->assertSame(Validator::SUCCESS, $this->validate(['tags' => []]));
        $this->assertSame(Validator::SUCCESS, $this->validate(['tags' => range(1, 50)]));
    }

    /**
     * An empty array is a submission of length nought, not an absent
     * argument -- so `min` is what rejects it, with the message that says so.
     */
    public function testAnEmptyArrayIsALengthOfNoughtRatherThanAMissingArgument(): void
    {
        $this->assertSame(Validator::SUCCESS, $this->validate(['tags' => []], ['min' => 0]));
        $this->assertSame(Validator::ERROR, $this->validate(['tags' => []], ['min' => 1]));

        $this->assertStringContainsString('too few', implode(' ', $this->vm->getReport()->getErrorMessages()));
    }

    // --- what counts as present --------------------------------------------

    /** Only an array has a length; a scalar under the same name is not one. */
    public function testAScalarParameterIsRejectedRatherThanCounted(): void
    {
        $this->assertSame(Validator::ERROR, $this->validate(['tags' => 'not an array']));
    }

    public function testAnAbsentArgumentIsRejectedWhenRequired(): void
    {
        $this->assertSame(Validator::ERROR, $this->validate([], ['required' => true]));
        $this->assertStringContainsString('tags missing', implode(' ', $this->vm->getReport()->getErrorMessages()));
    }

    public function testAnAbsentArgumentIsToleratedWhenNotRequired(): void
    {
        $this->assertNotSame(Validator::ERROR, $this->validate([], ['required' => false]));
    }

    /**
     * "Not required" is decided by the same precheck that asks whether the
     * argument is an array, so an optional validator handed a scalar reports
     * NOT_PROCESSED rather than an error: it never gets as far as counting.
     * A parameter that must be an array therefore needs `required`, or a type
     * check ahead of this one.
     */
    public function testAnOptionalValidatorSkipsAScalarInsteadOfRejectingIt(): void
    {
        $this->assertSame(
            Validator::NOT_PROCESSED,
            $this->validate(['tags' => 'not an array'], ['required' => false]),
        );
    }
}
