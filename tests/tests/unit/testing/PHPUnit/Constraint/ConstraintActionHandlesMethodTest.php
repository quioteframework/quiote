<?php

use Quiote\Action\Action;
use Quiote\Exception\QuioteException;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Testing\PHPUnit\Constraint\ConstraintActionHandlesMethod;

class ConstraintActionHandlesMethodTest extends PhpUnitTestCase
{
    private function newAction(): Action
    {
        return new class extends Action {
            public function executeRead(): string { return 'Success'; }
        };
    }

    public function testMatchesReturnsTrueForImplementedSpecificMethod(): void
    {
        $constraint = new ConstraintActionHandlesMethod($this->newAction(), false);
        $this->assertTrue($constraint->matches('Read'));
    }

    public function testMatchesReturnsFalseForUnimplementedSpecificMethodWithoutGenericFallback(): void
    {
        $constraint = new ConstraintActionHandlesMethod($this->newAction(), false);
        $this->assertFalse($constraint->matches('Write'));
    }

    public function testMatchesThrowsWhenOtherIsNotAString(): void
    {
        $constraint = new ConstraintActionHandlesMethod($this->newAction(), false);

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessageMatches('/expects a string method name/');
        $constraint->matches(['Read']);
    }

    public function testCustomFailureDescriptionRendersTheGivenStringMethod(): void
    {
        $constraint = new ConstraintActionHandlesMethod($this->newAction(), false);
        $method = new \ReflectionMethod($constraint, 'customFailureDescription');

        $description = $method->invoke($constraint, 'Write', '', false);

        $this->assertIsString($description);
        $this->assertStringContainsString('handles method "Write"', $description);
    }

    public function testCustomFailureDescriptionThrowsWhenOtherIsNotAString(): void
    {
        $constraint = new ConstraintActionHandlesMethod($this->newAction(), false);
        $method = new \ReflectionMethod($constraint, 'customFailureDescription');

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessageMatches('/expects a string method name/');
        $method->invoke($constraint, ['Write'], '', false);
    }

    /**
     * The negated description is what a failing assertNotHandlesMethod()
     * prints, so it has to read as the opposite claim rather than repeat the
     * positive one.
     */
    public function testCustomFailureDescriptionReadsAsADenialWhenNegated(): void
    {
        $constraint = new ConstraintActionHandlesMethod($this->newAction(), false);
        $method = new \ReflectionMethod($constraint, 'customFailureDescription');

        $description = $method->invoke($constraint, 'Read', '', true);

        $this->assertIsString($description);
        $this->assertStringContainsString('does not handle method "Read"', $description);
    }

    /**
     * PHPUnit prints toString() as part of a failing assertThat(), so it has
     * to name the action class under test.
     */
    public function testToStringNamesTheActionUnderTest(): void
    {
        $action = $this->newAction();
        $constraint = new ConstraintActionHandlesMethod($action, false);

        $this->assertSame($action::class . ' handles method', $constraint->toString());
    }
}

?>
