<?php

use Quiote\Exception\ConfigurationException;
use Quiote\Exception\QuioteException;
use Quiote\Testing\ActionTestCase;

/**
 * Covers ActionTestCase::runAction()/performValidation() behavior preserved (and
 * newly guarded) while bringing Quiote/Testing up to PHPStan level 9: a normal
 * action must still resolve to the expected view name, and an action returning
 * a non-string view name must now fail loudly instead of silently coercing it.
 */
class ActionTestCaseTest extends ActionTestCase
{
    protected $moduleName = 'ControllerTests';
    protected $actionName = 'SimpleAction';

    public function testRunActionResolvesSimpleActionDefaultView(): void
    {
        $this->setRequestMethod('read');
        $this->performValidation();
        $this->runAction();

        $this->assertTrue($this->validationSuccess);
        $this->assertViewNameEquals('Success');
        $this->assertViewModuleNameEquals('ControllerTests');
    }

    public function testRunActionThrowsWhenActionReturnsNonStringViewName(): void
    {
        $this->actionName = 'InvalidViewReturn';
        $this->setRequestMethod('read');

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessageMatches('/must return a string view name/');
        $this->runAction();
    }

    /**
     * Regression for the incident where a bad validator config (an unwhitelisted
     * XML parameter, caught by ValidatorPlanBuilder::checkParameters()) got
     * swallowed by performValidation() into a plain ValidationResult::failure(),
     * surfacing as a mystery "childCount: 0" / "expected success, got failure"
     * test failure with no trace of the actual ConfigurationException. It must
     * now propagate as a real PHPUnit error instead.
     */
    public function testPerformValidationPropagatesConfigurationExceptionFromBadValidatorConfig(): void
    {
        $this->actionName = 'BadValidator';
        $this->setRequestMethod('read');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unknown parameter "values" on validator "bad" \(Quiote\\\\Validator\\\\StringValidator\)/');
        $this->performValidation();
    }
}

?>
