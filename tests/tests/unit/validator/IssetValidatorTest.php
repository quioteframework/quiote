<?php

declare(strict_types=1);

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\IssetValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

/**
 * IssetValidator asserts only that a parameter is present -- never anything
 * about its content. That distinction is the whole class: an empty but
 * submitted field passes, and a field that was never submitted does not.
 *
 * Making the empty case pass takes a deliberate override. Validator's normal
 * "are all arguments set" precheck treats an empty value as absent and would
 * short-circuit before validate() ever ran, so a required IssetValidator
 * declares its arguments set and decides for itself.
 */
final class IssetValidatorTest extends UnitTestCase
{
    private ValidationManager $vm;

    #[\Override]
    public function setUp(): void
    {
        $this->vm = $this->getContext()->getContainer()->get(ValidationManager::class);
    }

    /** @param array<string, mixed> $parameters */
    private function validate(array $parameters, bool $required = true): int
    {
        $validator = $this->vm->createValidator(
            IssetValidator::class,
            ['field'],
            ['' => 'field is missing'],
            ['required' => $required],
        );

        // Whitelist the argument name: the not-required path goes through Validator's own
        // precheck, which reads the parameter, and strict validation refuses an un-whitelisted read.
        return $validator->execute($this->newWebRequest($parameters, ['field']));
    }

    public function testASubmittedValuePasses(): void
    {
        $this->assertSame(Validator::SUCCESS, $this->validate(['field' => 'a value']));
    }

    /**
     * The point of the class: presence, not content. A submitted-but-empty
     * field is set, so it passes -- which is what makes this usable for "the
     * checkbox was on the form" rather than "the field has content".
     */
    public function testASubmittedButEmptyValuePasses(): void
    {
        $this->assertSame(Validator::SUCCESS, $this->validate(['field' => '']));
    }

    public function testAZeroValuePasses(): void
    {
        $this->assertSame(Validator::SUCCESS, $this->validate(['field' => '0']));
        $this->assertSame(Validator::SUCCESS, $this->validate(['field' => 0]));
    }

    public function testAnAbsentParameterFails(): void
    {
        $this->assertSame(Validator::ERROR, $this->validate(['other' => 'a value']));
    }

    public function testNoParametersAtAllFails(): void
    {
        $this->assertSame(Validator::ERROR, $this->validate([]));
    }

    /**
     * Every named argument has to be present; one missing is enough to fail,
     * so a partially-submitted form does not pass as complete.
     */
    public function testEveryNamedArgumentMustBePresent(): void
    {
        $validator = $this->vm->createValidator(
            IssetValidator::class,
            ['first', 'second'],
            ['' => 'a field is missing'],
            ['required' => true],
        );

        $this->assertSame(
            Validator::SUCCESS,
            $validator->execute($this->newWebRequest(['first' => 'a', 'second' => ''])),
        );

        $other = $this->vm->createValidator(
            IssetValidator::class,
            ['first', 'second'],
            ['' => 'a field is missing'],
            ['required' => true],
        );

        $this->assertSame(Validator::ERROR, $other->execute($this->newWebRequest(['first' => 'a'])));
    }

    /**
     * Not required is the ordinary Validator behaviour: the precheck decides,
     * and an absent argument is simply not this validator's business.
     */
    public function testAnOptionalValidatorLeavesAnAbsentArgumentAlone(): void
    {
        $this->assertNotSame(Validator::ERROR, $this->validate([], required: false));
    }

    public function testAnOptionalValidatorStillPassesForASubmittedValue(): void
    {
        $this->assertSame(Validator::SUCCESS, $this->validate(['field' => 'a value'], required: false));
    }

    /** The configured message is what the report carries for the failure. */
    public function testTheConfiguredMessageIsReportedOnFailure(): void
    {
        $validator = $this->vm->createValidator(
            IssetValidator::class,
            ['field'],
            ['' => 'field is missing'],
            ['required' => true, 'name' => 'issetCheck'],
        );

        $this->assertSame(Validator::ERROR, $validator->execute($this->newWebRequest([])));
        $this->assertStringContainsString('field is missing', implode(' ', $this->vm->getReport()->getErrorMessages()));
    }
}
