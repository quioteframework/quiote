<?php

use Quiote\Context;
use Quiote\DI\NotFoundException;
use Quiote\Testing\UnitTestCase;

/**
 * The on-demand slots the factories configuration declares.
 *
 * This suite used to test `Context::getFactoryInfo()`'s normalization of two historical shapes for a
 * factory declaration. Both the method and the mirror it read are gone: a slot is a transient container
 * binding, so what is left worth asserting is that resolving one works, that it is transient, and that
 * asking for a slot nobody declared fails as a container lookup error rather than answering null.
 */
class ContextFactoryInfoTest extends UnitTestCase
{
    public function testADeclaredSlotResolvesAndIsTransient(): void
    {
        $container = $this->getContext()->getContainer();

        $first = $container->get(\Quiote\Validator\ValidationManager::class);
        $second = $container->get(\Quiote\Validator\ValidationManager::class);

        $this->assertInstanceOf(\Quiote\Validator\ValidationManager::class, $first);
        $this->assertNotSame($first, $second);
    }

    /**
     * A slot is reachable by the class its declaration names *and* by every ancestor of that class, so
     * an application configuring its own subclass stays reachable as the base type a consumer
     * type-hints.
     */
    public function testASlotIsReachableByRoleAndByType(): void
    {
        $container = $this->getContext()->getContainer();

        $this->assertInstanceOf(\Quiote\Validator\ValidationManager::class, $container->get('validation_manager'));
        $this->assertInstanceOf(\Quiote\Response\WebResponse::class, $container->get('response'));
    }

    public function testAnUndeclaredSlotIsAContainerError(): void
    {
        // NotFoundException specifically: createInstanceFor() used to answer "No factory info for ..."
        // and the container says the same thing in its own vocabulary.
        $this->expectException(NotFoundException::class);

        $this->getContext()->getContainer()->get('no_such_factory');
    }
}
