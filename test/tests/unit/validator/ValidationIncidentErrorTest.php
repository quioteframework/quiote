<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviValidationIncident;
use Agavi\Validator\AgaviValidationError;
use Agavi\Validator\AgaviValidationArgument;
use Agavi\Validator\AgaviValidator;

/**
 * Tests for AgaviValidationIncident and AgaviValidationError data/relationship behavior.
 */
class ValidationIncidentErrorTest extends AgaviUnitTestCase
{
    public function testAddErrorLinksIncidentAndArguments(): void
    {
        $incident = new AgaviValidationIncident(null, AgaviValidator::ERROR);
        $err = new AgaviValidationError('msg', 'code1', ['fieldA', new AgaviValidationArgument('fieldB')]);
        $incident->addError($err);
        $this->assertSame($incident, $err->getIncident());
        $args = $incident->getArguments();
        $this->assertCount(2, $args);
        $this->assertArrayHasKey((new AgaviValidationArgument('fieldA'))->getHash(), $args);
        $this->assertArrayHasKey((new AgaviValidationArgument('fieldB'))->getHash(), $args);
    }

    public function testSetErrorsNormalizesAndOverwrites(): void
    {
        $incident = new AgaviValidationIncident(null, AgaviValidator::NOTICE);
        $err1 = new AgaviValidationError('m1', 'n1', ['f1']);
        $err2 = new AgaviValidationError('m2', 'n2', ['f2']);
        $incident->setErrors([$err1, $err2]);
        $errs = $incident->getErrors();
        $this->assertCount(2, $errs);
        foreach ($errs as $e) { $this->assertSame($incident, $e->getIncident()); }
    // Deprecated/legacy hasFieldError internally relies on hasArgumentError (removed); instead assert via getFields
    $this->assertContains('f1', $incident->getFields());
    $this->assertContains('f2', $incident->getFields());
        $this->assertSame(['f1','f2'], $incident->getFields());
    }

    public function testValidationErrorArgumentNormalizationAndLookup(): void
    {
        $err = new AgaviValidationError('m', 'codeX', ['x','y']);
        $this->assertTrue($err->hasField('x'));
        $this->assertFalse($err->hasField('z'));
        $args = $err->getArguments();
        $this->assertCount(2, $args);
    }

    public function testResetClearsState(): void
    {
        $incident = new AgaviValidationIncident(null, AgaviValidator::CRITICAL);
        $err = new AgaviValidationError('boom','critical_err',['a']);
        $incident->addError($err);
        $incident->reset();
        $this->assertSame([], $incident->getErrors());
        $this->assertSame(AgaviValidator::ERROR, $incident->getSeverity(), 'Severity resets to ERROR constant default');

        $err->reset();
        $this->assertSame([], $err->getArguments());
        $this->assertSame('', $err->getMessage());
        $this->assertSame('', $err->getName());
    }
}
