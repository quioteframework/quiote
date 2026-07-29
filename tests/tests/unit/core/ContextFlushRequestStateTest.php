<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Quiote\Session\QuioteSessionBag;
use Quiote\Session\SessionManager;
use Quiote\Testing\UnitTestCase;
use Quiote\User\RbacSecurityUser;
use Quiote\User\SecurityUser;

/**
 * Context::flushRequestState() -- where the user's session writes happen, and
 * the fix for the defect that had them running on the wrong side of the
 * response being emitted.
 *
 * The session was persisted on the pipeline unwind, but the user (the only
 * writer of roles, credentials and attributes) was shut down later, from
 * Context::reset(), after the response had gone out. Its writes therefore
 * landed somewhere nothing would ever persist. The visible symptom was a login
 * that produced authenticated=true with no roles and no credentials, and so a
 * 403 on every following request.
 */
class ContextFlushRequestStateTest extends UnitTestCase
{
    private function peek(Context $context, string $property): mixed
    {
        return (new ReflectionObject($context))->getProperty($property)->getValue($context);
    }

    private function poke(Context $context, string $property, mixed $value): void
    {
        (new ReflectionObject($context))->getProperty($property)->setValue($context, $value);
    }

    /**
     * Context::getInstance() hands back one shared instance per name, so the
     * flush token survives between tests. Re-arm it: in a real request that is
     * handle()'s and reset()'s job.
     */
    private function armFlush(Context $context): void
    {
        $this->poke($context, 'requestStateFlushed', false);
    }

    private function context(string $name): Context
    {
        $context = Context::getInstance($name);
        $this->armFlush($context);

        return $context;
    }

    /**
     * The whole login chain against a real session backend: the user's state
     * has to be in the session by the time the session is persisted.
     */
    public function testRolesAndCredentialsReachTheSessionBeforeItIsPersisted(): void
    {
        $context = $this->context('context-flush-test::tests-flush-persists-user');
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence);
        $request = new ServerRequest('GET', '/');
        $session = $manager->startFromRequest($request);
        $context->setSessionBag(new QuioteSessionBag($manager, $session, $request));

        $user = new RbacSecurityUser();
        $user->initialize($context, [
            'definitions_file' => \Quiote\Config\Config::getString('core.config_dir') . '/tests/rbac_definitions.xml',
        ]);
        $this->poke($context, 'user', $user);

        // What AuthenticationManager::apply() does at login.
        $user->setAuthenticated(true);
        $user->grantRole('administrator');

        $context->flushRequestState();

        // Only now does the middleware persist the session -- which is the
        // ordering under test.
        $manager->persist($session);

        $stored = $persistence->load($session->getId());
        $this->assertIsArray($stored);
        $this->assertTrue($stored[SecurityUser::AUTH_NAMESPACE] ?? null, 'authenticated flag must persist');
        $this->assertSame(
            ['administrator'],
            $stored[RbacSecurityUser::ROLES_NAMESPACE] ?? null,
            'roles must reach the session -- their absence is what produced authenticated-and-403',
        );
        $this->assertNotEmpty(
            $stored[SecurityUser::CREDENTIAL_NAMESPACE] ?? [],
            'credentials derived from the granted role must reach the session too',
        );
    }

    /**
     * The ordering itself: the user writes into the session, so it must be
     * flushed before anything persists the session.
     */
    public function testFlushWritesTheUserIntoTheSession(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new RecordingUser());

        $context->flushRequestState();

        $this->assertSame(['user.shutdown'], RecordingSessionBag::calls());
    }

    public function testFlushIsIdempotentWithinASingleRequest(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new RecordingUser());

        $context->flushRequestState();
        $context->flushRequestState();
        $context->flushRequestState();

        $this->assertSame(['user.shutdown'], RecordingSessionBag::calls(), 'the first caller wins');
    }

    /**
     * Creating a user at unwind for a request that never asked for one would
     * establish a session -- and a cookie -- for an anonymous visitor.
     * flushRequestState() therefore reads the property and never calls
     * getUser().
     */
    public function testFlushDoesNotCreateAUserWhenNoneExists(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', null);

        $context->flushRequestState();

        $this->assertNull($this->peek($context, 'user'), 'no user must be created by the flush');
        $this->assertSame([], RecordingSessionBag::calls());
    }

    /**
     * A sessionless request (auth.sessionless / jwt.skip_session) must still
     * claim the flush, so the post-emit reset() does not attempt a late write.
     */
    public function testFlushWithPersistUserFalseWritesNothingButStillClaims(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new RecordingUser());

        $context->flushRequestState(persistUser: false);
        $this->assertSame([], RecordingSessionBag::calls());

        $context->flushRequestState();
        $this->assertSame([], RecordingSessionBag::calls(), 'the flush was already claimed');
    }

    /**
     * Failure path: a throwing user shutdown must not escape into the response
     * path.
     */
    public function testAThrowingUserShutdownDoesNotPropagate(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new ThrowingUser());

        $context->flushRequestState();

        $this->addToAssertionCount(1);
    }

    public function testResetDoesNotWriteAgainWhenTheFlushAlreadyRan(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new RecordingUser());
        $this->poke($context, 'shutdownSequence', []);

        $context->flushRequestState();
        $context->reset();

        $this->assertSame(['user.shutdown'], RecordingSessionBag::calls());
    }

    /**
     * The backstop: a request that never reached the session middleware still
     * gets its state flushed from reset().
     */
    public function testResetFlushesWhenNothingElseDid(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new RecordingUser());
        $this->poke($context, 'shutdownSequence', []);

        $context->reset();

        $this->assertSame(['user.shutdown'], RecordingSessionBag::calls());
    }

    /**
     * A bag surviving the request boundary would serve request N's session to
     * request N+1 -- a cross-user leak.
     */
    public function testResetDropsTheSessionBag(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        $bag = new RecordingSessionBag();
        $context->setSessionBag($bag);
        $this->poke($context, 'shutdownSequence', []);

        $context->reset();

        $this->assertNotSame($bag, $context->getSessionBag());
        $this->assertInstanceOf(\Quiote\Session\NullSessionBag::class, $context->getSessionBag());
    }

    /**
     * The token must be re-armed between requests, or request two in a worker
     * would never persist anything.
     */
    public function testResetReArmsTheFlushForTheNextRequest(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        RecordingSessionBag::resetLog();
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new RecordingUser());
        $this->poke($context, 'shutdownSequence', []);

        $context->flushRequestState();
        $context->reset();

        // Next request.
        $context->setSessionBag(new RecordingSessionBag());
        $this->poke($context, 'user', new RecordingUser());
        $context->flushRequestState();

        $this->assertSame(['user.shutdown', 'user.shutdown'], RecordingSessionBag::calls());
    }

    /**
     * With no session slot configured -- a console command, a queue worker, a
     * stateless API -- consumers still get something to talk to.
     */
    public function testAContextWithNoSessionConfiguredAnswersANullBag(): void
    {
        $context = $this->context('context-flush-test::tests-flush-ordering');
        $context->setSessionBag(null);

        $bag = $context->getSessionBag();

        $this->assertInstanceOf(\Quiote\Session\NullSessionBag::class, $bag);
        $bag->set('k', 'v');
        $this->assertNull($bag->get('k'), 'writes are discarded');
        $this->assertFalse($bag->exists());
        $this->assertSame('', $bag->getId());
    }
}

/**
 * Minimal user double recording into RecordingSessionBag's shared log, so user
 * and session activity are observable in one ordered sequence.
 */
class RecordingUser
{
    public function shutdown(): void
    {
        RecordingSessionBag::$log[] = 'user.shutdown';
    }
}

class ThrowingUser
{
    public function shutdown(): void
    {
        throw new \RuntimeException('user shutdown exploded');
    }
}
