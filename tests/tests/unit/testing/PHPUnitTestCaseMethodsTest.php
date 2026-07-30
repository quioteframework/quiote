<?php

use Quiote\Testing\PhpUnitTestCase;

/**
 * @quioteFixture toplevel-value
 */
class PHPUnitTestCaseMethodsTest extends PhpUnitTestCase
{
    /**
     * @quioteFixture method-value
     */
    public function testGetAnnotationsCollectsClassAndMethodDocBlockAnnotations(): void
    {
        $annotations = $this->getAnnotations();

        // The quiote-prefixed pattern and the generic annotation pattern both match
        // a "@quioteFixture" line, so the quiote-prefixed alias key collects the value twice.
        $this->assertSame(['toplevel-value'], $annotations['class']['fixture']);
        $this->assertSame(['toplevel-value', 'toplevel-value'], $annotations['class']['quioteFixture']);

        $this->assertSame(['method-value'], $annotations['method']['fixture']);
        $this->assertSame(['method-value', 'method-value'], $annotations['method']['quioteFixture']);
    }

    public function testGetAnnotationsIsSafeForATestMethodWithNoDocBlockAnnotations(): void
    {
        $annotations = $this->getAnnotations();

        $this->assertArrayNotHasKey('nonExistentAnnotation', $annotations['method']);
    }
}

?>
