<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Session\QuioteSessionBag;
use Quiote\Session\SessionBagInterface;
use Quiote\Session\SessionManager;
use Quiote\Testing\UnitTestCase;

/**
 * The SessionBagInterface contract again, this time over the PSR-7-native
 * SessionManager stack. Deliberately mirrors StorageSessionBagTest: the seam is
 * only worth anything if both adapters actually behave the same, so the same
 * questions get asked of both.
 */
class QuioteSessionBagTest extends UnitTestCase
{
    private function bag(?ServerRequest $request = null): QuioteSessionBag
    {
        $request ??= new ServerRequest('GET', '/');
        $manager = new SessionManager(new InMemorySessionPersistence());
        $session = $manager->startFromRequest($request);

        return new QuioteSessionBag($manager, $session, $request);
    }

    // ------------------------------------------------------- contract basics

    public function testGetReturnsTheDefaultForAMissingKey(): void
    {
        $bag = $this->bag();

        $this->assertNull($bag->get('absent'));
        $this->assertSame('fallback', $bag->get('absent', 'fallback'));
    }

    public function testSetThenGetRoundTrips(): void
    {
        $bag = $this->bag();

        $bag->set('k', ['nested' => 1]);

        $this->assertSame(['nested' => 1], $bag->get('k'));
        $this->assertTrue($bag->has('k'));
    }

    public function testRemoveDeletesTheKey(): void
    {
        $bag = $this->bag();
        $bag->set('k', 'v');

        $bag->remove('k');

        $this->assertFalse($bag->has('k'));
        $this->assertNull($bag->get('k'));
    }

    public function testItSatisfiesTheInterface(): void
    {
        $this->assertInstanceOf(SessionBagInterface::class, $this->bag());
    }

    // ------------------------------------------------------------- exists()

    /**
     * Same semantics as the legacy adapter's: an untouched brand-new session
     * reports absent, so a default/empty write does not persist a row or emit
     * a cookie for a client that never had a session.
     */
    public function testExistsIsFalseForAnUntouchedNewSession(): void
    {
        $this->assertFalse($this->bag()->exists());
    }

    public function testExistsBecomesTrueOnceSomethingIsWritten(): void
    {
        $bag = $this->bag();
        $bag->set('k', 'v');

        $this->assertTrue($bag->exists());
    }

    public function testExistsIsTrueForASessionLoadedFromPersistence(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('an-existing-session-id-1234567890', ['user_id' => 42]);
        $manager = new SessionManager($persistence);
        $request = (new ServerRequest('GET', '/'))
            ->withCookieParams(['QSID' => 'an-existing-session-id-1234567890']);
        $session = $manager->startFromRequest($request);

        $bag = new QuioteSessionBag($manager, $session, $request);

        $this->assertTrue($bag->exists());
        $this->assertSame(42, $bag->get('user_id'));
    }

    // -------------------------------------------------- id / regenerate

    public function testGetIdReturnsTheSessionId(): void
    {
        $bag = $this->bag();

        $this->assertNotSame('', $bag->getId());
        $this->assertSame($bag->getSession()->getId(), $bag->getId());
    }

    public function testRegeneratePreservesDataUnderANewId(): void
    {
        $bag = $this->bag();
        $bag->set('user_id', 7);
        $oldId = $bag->getId();

        $bag->regenerate(true);

        $this->assertNotSame($oldId, $bag->getId());
        $this->assertSame(7, $bag->get('user_id'));
    }

    public function testDestroyEmptiesTheSessionAndAbandonsTheId(): void
    {
        $bag = $this->bag();
        $bag->set('user_id', 7);
        $oldId = $bag->getId();

        $bag->destroy();

        $this->assertNotSame($oldId, $bag->getId());
        $this->assertNull($bag->get('user_id'), 'destroy() discards contents, unlike regenerate()');
    }

    // ---------------------------------------------------------- integration

    public function testTheMiddlewareInstallsTheBagOnTheContext(): void
    {
        $context = \Quiote\Context::getInstance('user-dirty-test::tests-anonymous');
        $manager = new SessionManager(new InMemorySessionPersistence());
        $middleware = new \Quiote\Session\SessionMiddleware($manager, $context);

        $handler = new class ($context) implements \Psr\Http\Server\RequestHandlerInterface {
            public mixed $seen = null;

            public function __construct(private \Quiote\Context $context) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seen = $this->context->getSessionBag();

                return new \Nyholm\Psr7\Response();
            }
        };

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertInstanceOf(
            QuioteSessionBag::class,
            $handler->seen,
            'framework consumers must reach the same session as the middleware',
        );

        $context->setSessionBag(null);
    }

    /**
     * With no session configured at all, consumers still get something to talk
     * to rather than having to check first.
     */
    public function testAContextWithNoSessionAnswersANullBag(): void
    {
        $context = \Quiote\Context::getInstance('user-dirty-test::tests-anonymous');
        $context->setSessionBag(null);

        $this->assertInstanceOf(\Quiote\Session\NullSessionBag::class, $context->getSessionBag());
    }
}
