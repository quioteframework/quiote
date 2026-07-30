<?php

use Quiote\Testing\LightweightTestContainer;
use Quiote\Testing\PhpUnitTestCase;

class LightweightTestContainerTest extends PhpUnitTestCase
{
    public function testAttributeHolderRoundTrip(): void
    {
        $container = new LightweightTestContainer();
        $container->setAttribute('foo', 'bar');

        $this->assertTrue($container->hasAttribute('foo'));
        $this->assertSame('bar', $container->getAttribute('foo'));
        $this->assertSame(['foo'], $container->getAttributeNames());

        $container->removeAttribute('foo');
        $this->assertFalse($container->hasAttribute('foo'));
    }

    public function testGetAttributeReturnsDefaultWhenMissing(): void
    {
        $container = new LightweightTestContainer();
        $this->assertNull($container->getAttribute('missing'));
        $this->assertSame('fallback', $container->getAttribute('alsoMissing', null, 'fallback'));
    }

    public function testValidationManagerStubAlwaysReportsUntouchedArguments(): void
    {
        $container = new LightweightTestContainer();
        $manager = $container->getValidationManager();
        $report = (new \ReflectionMethod($manager, 'getReport'))->invoke($manager);
        $this->assertIsObject($report);

        $this->assertFalse((new \ReflectionMethod($report, 'isArgumentValidated'))->invoke($report, 'anything'));
        $this->assertFalse((new \ReflectionMethod($report, 'isArgumentFailed'))->invoke($report, 'anything'));
        $this->assertSame([], (new \ReflectionMethod($report, 'getErrorMessages'))->invoke($report));
    }

    public function testGetValidationManagerReturnsSameStubOnRepeatedCalls(): void
    {
        $container = new LightweightTestContainer();
        $this->assertSame($container->getValidationManager(), $container->getValidationManager());
    }

    public function testSetValidationManagerOverridesTheLazyStub(): void
    {
        $container = new LightweightTestContainer();
        $injected = new stdClass();
        $container->setValidationManager($injected);

        $this->assertSame($injected, $container->getValidationManager());
    }
}

?>
