<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Quiote\Context;
use Quiote\Storage\SessionStorage;
use Quiote\Testing\UnitTestCase;
use Quiote\User\RbacSecurityUser;
use Quiote\User\SecurityUser;

/**
 * Context::flushRequestState() -- the single owner of "persist the user, then
 * close the session", and the fix for the defect where those two ran on
 * opposite sides of the response being emitted.
 *
 * The session was closed by SessionMiddleware on the pipeline unwind, but the
 * user (the only writer of roles, credentials and attributes) was shut down
 * later, from Context::reset(), after the response had gone out. Its
 * store() calls then hit SessionStorage::store()'s lazy @session_start(),
 * which fails silently once headers are sent -- so the data landed in a
 * $_SESSION nothing would ever persist. The visible symptom was a login that
 * produced authenticated=true with no roles and no credentials, and therefore
 * a 403 on every following request.
 */
class ContextFlushRequestStateTest extends UnitTestCase
{
    private function contextWithSessionStorage(string $name): Context
    {
        $context = Context::getInstance($name);
        $storage = new SessionStorage();
        $storage->initialize($context);
        (new ReflectionObject($context))->getProperty('storage')->setValue($context, $storage);
        $this->armFlush($context);

        return $context;
    }

    /**
     * Context::getInstance() hands back one shared instance per name, so the
     * flush token survives between tests in this class. Re-arm it explicitly:
     * in a real request that is handle()'s and reset()'s job.
     */
    private function armFlush(Context $context): void
    {
        $this->poke($context, 'requestStateFlushed', false);
    }

    /**
     * Read a private/protected Context property.
     */
    private function peek(Context $context, string $property): mixed
    {
        return (new ReflectionObject($context))->getProperty($property)->getValue($context);
    }

    private function poke(Context $context, string $property, mixed $value): void
    {
        (new ReflectionObject($context))->getProperty($property)->setValue($context, $value);
    }

    /**
     * Roles are granted after setAuthenticated(true) and are only ever written
     * by the user's shutdown(), so they persist if and only if that shutdown
     * happens before the session is closed.
     *
     * Note what this can and cannot prove. It verifies the whole login ->
     * flush -> reload chain against the real backing store, but it cannot
     * reproduce the original defect, whose trigger was
     * SessionStorage::store()'s lazy @session_start() failing *because headers
     * had already been sent* -- unreachable in a test process. The
     * discriminating regression guard for the ordering is
     * testFlushShutsDownTheUserBeforeTheStorage below; the end-to-end proof is
     * the multi-request worker integration scenario.
     */
    #[RunInSeparateProcess]
    public function testRolesAndCredentialsSurviveTheRequestBoundaryFlush(): void
    {
        $context = $this->contextWithSessionStorage('context-flush-test::tests-flush-persists-user');
        $storage = $context->getStorage();

        // Same definitions path the other RBAC tests use: the sandbox keeps
        // them under Config/tests/, not at loadDefinitions()' default location.
        $user = new RbacSecurityUser();
        $user->initialize($context, [
            'definitions_file' => \Quiote\Config\Config::getString('core.config_dir') . '/tests/rbac_definitions.xml',
        ]);
        $this->poke($context, 'user', $user);

        // What AuthenticationManager::apply() does at login.
        $user->setAuthenticated(true);
        $user->grantRole('administrator');

        $sessionId = session_id();
        $this->assertIsString($sessionId);
        $this->assertNotSame('', $sessionId, 'precondition: login must have created a session');

        $context->flushRequestState();

        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'the flush must close the session');

        // Drop the in-memory copy first: session_write_close() leaves $_SESSION
        // populated, so without this the assertions below could be satisfied by
        // data that never reached the backing store.
        $_SESSION = [];

        // Re-open the very same session id and read back what was persisted.
        session_id($sessionId);
        session_start();

        // Read back through the storage API, the same way the next request's
        // SecurityUser::initialize() would.
        $this->assertTrue($storage->retrieve(SecurityUser::AUTH_NAMESPACE), 'authenticated flag must persist');
        $this->assertSame(
            ['administrator'],
            $storage->retrieve(RbacSecurityUser::ROLES_NAMESPACE),
            'roles must reach the session -- their absence is what produced authenticated-and-403',
        );
        $this->assertNotEmpty(
            $storage->retrieve(SecurityUser::CREDENTIAL_NAMESPACE),
            'credentials derived from the granted role must reach the session too',
        );
    }

    /**
     * The ordering itself, observed directly: the user writes into the session,
     * so it must be shut down before storage closes it.
     */
    public function testFlushShutsDownTheUserBeforeTheStorage(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $storage = new RecordingStorage();
        $this->poke($context, 'storage', $storage);
        $this->poke($context, 'user', new RecordingUser());

        $context->flushRequestState();

        $calls = array_values(array_filter(
            RecordingStorage::calls(),
            static fn(string $call): bool => in_array($call, ['user.shutdown', 'storage.shutdown'], true),
        ));

        $this->assertSame(['user.shutdown', 'storage.shutdown'], $calls);
    }

    public function testFlushIsIdempotentWithinASingleRequest(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new RecordingUser());

        $context->flushRequestState();
        $context->flushRequestState();
        $context->flushRequestState();

        $this->assertSame(
            1,
            count(RecordingStorage::callersOf('storage.shutdown')),
            'the first caller wins; later callers must not write again',
        );
        $this->assertSame(1, count(RecordingStorage::callersOf('user.shutdown')));
    }

    /**
     * Creating a user at unwind for a request that never asked for one would
     * manufacture a session row -- and a Set-Cookie -- for an anonymous
     * visitor. flushRequestState() therefore reads the property directly and
     * never calls getUser().
     */
    public function testFlushDoesNotCreateAUserWhenNoneExists(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', null);

        $context->flushRequestState();

        $this->assertNull($this->peek($context, 'user'), 'no user must be created by the flush');
        $this->assertSame([], RecordingStorage::callersOf('user.shutdown'));
        $this->assertSame(1, count(RecordingStorage::callersOf('storage.shutdown')), 'storage is still closed');
    }

    /**
     * A sessionless request (auth.sessionless / jwt.skip_session) has no
     * session to persist into, but must still claim the flush so the post-emit
     * reset() does not attempt a late write.
     */
    public function testFlushWithPersistUserFalseClosesStorageWithoutWritingTheUser(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new RecordingUser());

        $context->flushRequestState(persistUser: false);

        $this->assertSame([], RecordingStorage::callersOf('user.shutdown'));
        $this->assertSame(1, count(RecordingStorage::callersOf('storage.shutdown')));

        // And the flush is claimed: a later backstop call is a no-op.
        $context->flushRequestState();
        $this->assertSame([], RecordingStorage::callersOf('user.shutdown'));
    }

    /**
     * Failure path: if the user write throws, the session must still be closed,
     * or a worker carries an open session into the next request.
     */
    public function testFlushStillClosesStorageWhenTheUserShutdownThrows(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new ThrowingUser());

        $context->flushRequestState();

        $this->assertSame(
            1,
            count(RecordingStorage::callersOf('storage.shutdown')),
            'storage must be closed even when the user write blew up',
        );
    }

    /**
     * And the exception does not escape into the response path.
     */
    public function testAThrowingUserShutdownDoesNotPropagate(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new ThrowingUser());

        $context->flushRequestState();

        $this->addToAssertionCount(1);
    }

    public function testResetDoesNotWriteAgainWhenTheFlushAlreadyRan(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new RecordingUser());
        $this->poke($context, 'shutdownSequence', []);

        $context->flushRequestState();
        $context->reset();

        $this->assertSame(1, count(RecordingStorage::callersOf('storage.shutdown')));
        $this->assertSame(1, count(RecordingStorage::callersOf('user.shutdown')));
    }

    /**
     * The backstop: a request that never reached SessionMiddleware still gets
     * its state persisted, in the right order, from reset().
     */
    public function testResetFlushesWhenNothingElseDid(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new RecordingUser());
        $this->poke($context, 'shutdownSequence', []);

        $context->reset();

        $calls = array_values(array_filter(
            RecordingStorage::calls(),
            static fn(string $call): bool => in_array($call, ['user.shutdown', 'storage.shutdown'], true),
        ));
        $this->assertSame(['user.shutdown', 'storage.shutdown'], $calls);
    }

    /**
     * The token must be re-armed between requests, or request two in a worker
     * would never persist anything.
     */
    public function testResetReArmsTheFlushForTheNextRequest(): void
    {
        $context = Context::getInstance('context-flush-test::tests-flush-ordering');
        $this->armFlush($context);

        RecordingStorage::resetLog();
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new RecordingUser());
        $this->poke($context, 'shutdownSequence', []);

        $context->flushRequestState();
        $context->reset();

        // Next request.
        $this->poke($context, 'storage', new RecordingStorage());
        $this->poke($context, 'user', new RecordingUser());
        $context->flushRequestState();

        $this->assertSame(
            2,
            count(RecordingStorage::callersOf('storage.shutdown')),
            'the second request must be able to flush as well',
        );
    }
}

/**
 * Minimal user double recording into RecordingStorage's shared log, so
 * user/storage ordering is observable in one sequence.
 */
class RecordingUser
{
    public function shutdown(): void
    {
        RecordingStorage::$log[] = ['call' => 'user.shutdown', 'oid' => spl_object_id($this)];
    }
}

class ThrowingUser
{
    public function shutdown(): void
    {
        RecordingStorage::$log[] = ['call' => 'user.shutdown', 'oid' => spl_object_id($this)];

        throw new \RuntimeException('user shutdown exploded');
    }
}
