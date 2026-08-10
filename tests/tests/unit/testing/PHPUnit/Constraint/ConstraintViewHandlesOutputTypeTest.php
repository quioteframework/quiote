<?php

use Quiote\Exception\QuioteException;
use Quiote\Request\WebRequest;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Testing\PHPUnit\Constraint\ConstraintViewHandlesOutputType;
use Quiote\View\View;

class ConstraintViewHandlesOutputTypeTest extends PhpUnitTestCase
{
    private function newView(): View
    {
        return new class extends View {
            #[\Override]
            public function execute(WebRequest $rd) {}
            public function executeHtml(): void {}
        };
    }

    public function testMatchesReturnsTrueForImplementedOutputType(): void
    {
        $constraint = new ConstraintViewHandlesOutputType($this->newView(), false);
        $this->assertTrue($constraint->matches('Html'));
    }

    public function testMatchesReturnsFalseForUnimplementedOutputTypeWithoutGenericFallback(): void
    {
        $constraint = new ConstraintViewHandlesOutputType($this->newView(), false);
        $this->assertFalse($constraint->matches('Xml'));
    }

    public function testMatchesThrowsWhenOtherIsNotAString(): void
    {
        $constraint = new ConstraintViewHandlesOutputType($this->newView(), false);

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessageMatches('/expects a string output type name/');
        $constraint->matches(['Html']);
    }

    public function testCustomFailureDescriptionRendersTheGivenStringOutputType(): void
    {
        $constraint = new ConstraintViewHandlesOutputType($this->newView(), false);
        $method = new \ReflectionMethod($constraint, 'customFailureDescription');

        $description = $method->invoke($constraint, 'Xml', '', false);

        $this->assertIsString($description);
        $this->assertStringContainsString('handles output type "Xml"', $description);
    }

    public function testCustomFailureDescriptionThrowsWhenOtherIsNotAString(): void
    {
        $constraint = new ConstraintViewHandlesOutputType($this->newView(), false);
        $method = new \ReflectionMethod($constraint, 'customFailureDescription');

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessageMatches('/expects a string output type name/');
        $method->invoke($constraint, ['Xml'], '', false);
    }

    /**
     * The negated description is what a failing assertNotHandlesOutputType()
     * prints, so it has to read as the opposite claim rather than repeat the
     * positive one.
     */
    public function testCustomFailureDescriptionReadsAsADenialWhenNegated(): void
    {
        $constraint = new ConstraintViewHandlesOutputType($this->newView(), false);
        $method = new \ReflectionMethod($constraint, 'customFailureDescription');

        $description = $method->invoke($constraint, 'Html', '', true);

        $this->assertIsString($description);
        $this->assertStringContainsString('does not handle output type "Html"', $description);
    }

    /**
     * PHPUnit prints toString() as part of a failing assertThat(), so it has
     * to name the view class under test.
     */
    public function testToStringNamesTheViewUnderTest(): void
    {
        $view = $this->newView();
        $constraint = new ConstraintViewHandlesOutputType($view, false);

        $this->assertSame($view::class . ' handles output type', $constraint->toString());
    }
}

?>
