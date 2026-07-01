<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationIncident;
use Quiote\Validator\ValidationError;
use Quiote\Validator\ValidationArgument;
use Quiote\Validator\Validator;

/**
 * Tests for ValidationIncident and ValidationError data/relationship behavior.
 */
class ValidationIncidentErrorTest extends UnitTestCase
{
    public function testAddErrorLinksIncidentAndArguments(): void
    {
        $incident = new ValidationIncident(null, Validator::ERROR);
        $err = new ValidationError('msg', 'code1', ['fieldA', new ValidationArgument('fieldB')]);
        $incident->addError($err);
        $this->assertSame($incident, $err->getIncident());
        $args = $incident->getArguments();
        $this->assertCount(2, $args);
        $this->assertArrayHasKey((new ValidationArgument('fieldA'))->getHash(), $args);
        $this->assertArrayHasKey((new ValidationArgument('fieldB'))->getHash(), $args);
    }

    public function testSetErrorsNormalizesAndOverwrites(): void
    {
        $incident = new ValidationIncident(null, Validator::NOTICE);
        $err1 = new ValidationError('m1', 'n1', ['f1']);
        $err2 = new ValidationError('m2', 'n2', ['f2']);
        $incident->setErrors([$err1, $err2]);
        $errs = $incident->getErrors();
        $this->assertCount(2, $errs);
        foreach ($errs as $e) {
            $this->assertSame($incident, $e->getIncident());
        }
        // getArguments() is the modern replacement for the deprecated getFields();
        // derive the field names from the argument objects.
        $fieldNames = array_values(array_map(static fn($a) => $a->getName(), $incident->getArguments()));
        $this->assertContains('f1', $fieldNames);
        $this->assertContains('f2', $fieldNames);
        $this->assertSame(['f1', 'f2'], $fieldNames);
    }

    public function testValidationErrorArgumentNormalizationAndLookup(): void
    {
        $err = new ValidationError('m', 'codeX', ['x', 'y']);
        $this->assertTrue($err->hasField('x'));
        $this->assertFalse($err->hasField('z'));
        $args = $err->getArguments();
        $this->assertCount(2, $args);
    }

    public function testResetClearsState(): void
    {
        $incident = new ValidationIncident(null, Validator::CRITICAL);
        $err = new ValidationError('boom', 'critical_err', ['a']);
        $incident->addError($err);
        $incident->reset();
        $this->assertSame([], $incident->getErrors());
        $this->assertSame(Validator::ERROR, $incident->getSeverity(), 'Severity resets to ERROR constant default');

        $err->reset();
        $this->assertSame([], $err->getArguments());
        $this->assertSame('', $err->getMessage());
        $this->assertSame('', $err->getName());
    }
}
