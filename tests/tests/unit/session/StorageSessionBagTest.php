<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Quiote\Context;
use Quiote\Session\SessionBagInterface;
use Quiote\Session\StorageSessionBag;
use Quiote\Storage\NullStorage;
use Quiote\Storage\SessionStorage;
use Quiote\Testing\UnitTestCase;

/**
 * The SessionBagInterface contract, exercised against the legacy-Storage
 * adapter. Everything that needs session state -- the User hierarchy, CSRF
 * token storage, OIDC state -- talks to this interface rather than to a
 * particular session mechanism, so the contract is what has to hold.
 */
class StorageSessionBagTest extends UnitTestCase
{
    private function bagOverSessionStorage(string $contextName): StorageSessionBag
    {
        $context = Context::getInstance($contextName);
        $storage = new SessionStorage();
        $storage->initialize($context);

        return new StorageSessionBag($storage);
    }

    // ------------------------------------------------------- contract basics

    public function testGetReturnsTheDefaultForAMissingKey(): void
    {
        $bag = new StorageSessionBag(new MockStorage());

        $this->assertNull($bag->get('absent'));
        $this->assertSame('fallback', $bag->get('absent', 'fallback'));
    }

    public function testSetThenGetRoundTrips(): void
    {
        $bag = new StorageSessionBag(new MockStorage());

        $bag->set('k', ['nested' => 1]);

        $this->assertSame(['nested' => 1], $bag->get('k'));
        $this->assertTrue($bag->has('k'));
    }

    public function testRemoveDeletesTheKey(): void
    {
        $bag = new StorageSessionBag(new MockStorage());
        $bag->set('k', 'v');

        $bag->remove('k');

        $this->assertFalse($bag->has('k'));
        $this->assertNull($bag->get('k'));
    }

    /**
     * The normalization the seam exists to hide: SessionStorage answers null
     * for a missing key while NullStorage answers false. Consumers only ever
     * survived that difference through loose comparison.
     */
    public function testNullStorageMissAndSessionStorageMissLookIdentical(): void
    {
        $overNull = new StorageSessionBag(new NullStorage());
        $overMock = new StorageSessionBag(new MockStorage());

        $this->assertSame('default', $overNull->get('absent', 'default'));
        $this->assertSame('default', $overMock->get('absent', 'default'));
        $this->assertFalse($overNull->has('absent'));
        $this->assertFalse($overMock->has('absent'));
    }

    /**
     * A storage that implements none of the optional methods must degrade
     * quietly: the `storage` factory slot declares no must_implement, so an
     * application can legitimately supply one.
     */
    public function testABareStorageObjectDegradesQuietly(): void
    {
        $bag = new StorageSessionBag(new stdClass());

        $bag->set('k', 'v');
        $bag->remove('k');
        $bag->regenerate();
        $bag->destroy();

        $this->assertNull($bag->get('k'));
        $this->assertFalse($bag->has('k'));
        $this->assertSame('', $bag->getId());
        $this->assertTrue($bag->exists(), 'a backend with no session concept is always writable');
    }

    // ------------------------------------------------------------- exists()

    #[RunInSeparateProcess]
    public function testExistsIsFalseWithoutASessionAndTrueOnceThereIsOne(): void
    {
        $bag = $this->bagOverSessionStorage('quiote-session-storage-test::tests-lazy-retrieve');

        $this->assertFalse($bag->exists(), 'a cookieless request has no session to write into');

        $bag->set('k', 'v');

        $this->assertTrue($bag->exists());
    }

    public function testExistsIsTrueForABackendWithNoSessionConcept(): void
    {
        $this->assertTrue((new StorageSessionBag(new NullStorage()))->exists());
        $this->assertTrue((new StorageSessionBag(new MockStorage()))->exists());
    }

    // -------------------------------------------------------------- getId()

    #[RunInSeparateProcess]
    public function testGetIdIsEmptyUntilASessionExists(): void
    {
        $bag = $this->bagOverSessionStorage('quiote-session-storage-test::tests-getid-active');

        $this->assertSame('', $bag->getId());

        $bag->set('k', 'v');

        $this->assertNotSame('', $bag->getId());
        $this->assertSame(session_id(), $bag->getId());
    }

    // -------------------------------------------------- regenerate/destroy

    #[RunInSeparateProcess]
    public function testRegeneratePreservesDataUnderANewId(): void
    {
        $bag = $this->bagOverSessionStorage('quiote-session-storage-test::tests-regenerate');
        $bag->set('k', 'v');
        $oldId = $bag->getId();

        $bag->regenerate(true);

        $this->assertNotSame($oldId, $bag->getId());
        $this->assertSame('v', $bag->get('k'), 'regeneration moves the session, it does not empty it');
    }

    #[RunInSeparateProcess]
    public function testDestroyAbandonsTheCurrentId(): void
    {
        $bag = $this->bagOverSessionStorage('quiote-session-storage-test::tests-regenerate');
        $bag->set('k', 'v');
        $oldId = $bag->getId();

        $bag->destroy();

        $this->assertNotSame($oldId, $bag->getId(), 'the pre-destroy id must not be reused');
    }

    // ---------------------------------------------------------- integration

    public function testContextExposesALazyBagOverItsConfiguredStorage(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $storage = new MockStorage();
        (new ReflectionObject($context))->getProperty('storage')->setValue($context, $storage);

        $bag = $context->getSessionBag();

        $this->assertInstanceOf(SessionBagInterface::class, $bag);
        $bag->set('k', 'v');
        $this->assertSame('v', $storage->retrieve('k'), 'writes must reach the configured storage');
    }

    /**
     * A bag held past a storage replacement would read and write the discarded
     * instance's session -- the cross-request leak, relocated one layer up.
     */
    public function testTheBagIsRebuiltWhenStorageIsReplaced(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $storageProp = (new ReflectionObject($context))->getProperty('storage');

        $first = new MockStorage();
        $storageProp->setValue($context, $first);
        $bagOne = $context->getSessionBag();

        $second = new MockStorage();
        $storageProp->setValue($context, $second);
        $bagTwo = $context->getSessionBag();

        $this->assertNotSame($bagOne, $bagTwo);
        $bagTwo->set('k', 'v');
        $this->assertSame('v', $second->retrieve('k'));
        $this->assertNull($first->retrieve('k'), 'the discarded storage must not receive writes');
    }

    public function testAnExplicitlyInstalledBagIsNotSecondGuessed(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        (new ReflectionObject($context))->getProperty('storage')->setValue($context, new MockStorage());

        $custom = new StorageSessionBag(new MockStorage());
        $context->setSessionBag($custom);

        $this->assertSame($custom, $context->getSessionBag());

        $context->setSessionBag(null);
        $this->assertNotSame($custom, $context->getSessionBag(), 'clearing it restores the lazy default');
    }
}
