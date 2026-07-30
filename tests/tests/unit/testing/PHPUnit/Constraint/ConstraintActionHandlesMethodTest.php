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
}

?>
