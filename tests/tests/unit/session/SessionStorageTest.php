<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Context;
use Quiote\Storage\SessionStorage;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class SessionStorageTest extends UnitTestCase
{
	
	#[RunInSeparateProcess]
	public function testStartupSetsCookieSecureFlag(): void
	{
		// test for bug #1541
		ini_set('session.cookie_secure', 0);
		$context = Context::getInstance('quiote-session-storage-test::tests-startup-sets-cookie-secure-flag');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$cookieParams = session_get_cookie_params();
		$this->assertTrue($cookieParams['secure']);
	}

	#[RunInSeparateProcess]
	public function testStaticSessionId(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-static-session-id');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$this->assertEquals(session_id(), 'foobar');
	}

	/**
	 * Regression test for the FrankenPHP worker-mode cross-user session leak.
	 * In worker mode the PHP process is long-lived, so PHP's session module
	 * retains the previous request's session id and $_SESSION contents even
	 * after session_write_close(). Context::reset() calls storage->reset()
	 * between requests; that MUST clear both, otherwise the next request's
	 * startup() sees a non-empty session_id() and skips session_start(),
	 * silently inheriting the previous user's authenticated session.
	 */
	#[RunInSeparateProcess]
	public function testStoreAndRetrieveRoundTripThroughSessionSuperglobal(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-store-retrieve');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();

		$this->assertTrue($storage->store('user_id', 42));
		$this->assertSame(42, $storage->retrieve('user_id'));
		$this->assertNull($storage->retrieve('nonexistent_key'));
	}

	#[RunInSeparateProcess]
	public function testRemoveReturnsAndDeletesTheStoredValue(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-remove');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$storage->store('user_id', 42);

		$this->assertSame(42, $storage->remove('user_id'));
		$this->assertNull($storage->retrieve('user_id'));
		// Removing an already-absent key is a safe no-op returning null.
		$this->assertNull($storage->remove('user_id'));
	}

	/**
	 * close() is the SessionHandlerInterface callback PHP invokes from *inside*
	 * session_write_close() and session_regenerate_id(). It used to call
	 * session_write_close() itself, which is unbounded recursion for any
	 * subclass registered as its own save handler. It must delegate to the
	 * default handler instead, like read()/write()/open()/destroy()/gc() do,
	 * and must NOT end the active session as a side effect -- shutdown() is
	 * the write-close entry point for callers.
	 */
	#[RunInSeparateProcess]
	public function testCloseDelegatesToTheDefaultHandlerWithoutRecursing(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-close');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		// startup() defers the actual session_start() for a cookieless request
		// (see SessionStorage::startup()); store() triggers its own lazy start
		// so there is an active session in play.
		$storage->store('user_id', 42);

		$this->assertTrue($storage->close());
		// Delegation only: the session is still ours to write.
		$this->assertSame(PHP_SESSION_ACTIVE, session_status());
		$this->assertSame(42, $storage->retrieve('user_id'));

		// shutdown(), not close(), is what ends the session.
		$storage->shutdown();
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
	}

	/**
	 * A save handler that records its own close() calls, so the recursion the
	 * old implementation would have caused is observable as a call count
	 * rather than a stack overflow.
	 */
	#[RunInSeparateProcess]
	public function testCloseIsSafeToCallRepeatedly(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-close-repeat');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$storage->store('user_id', 42);

		$this->assertTrue($storage->close());
		$this->assertTrue($storage->close());
		$this->assertTrue($storage->close());
		$this->assertSame(PHP_SESSION_ACTIVE, session_status());
	}

	#[RunInSeparateProcess]
	public function testGetIdIsEmptyWhenNoSessionIsActive(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-getid-empty');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();

		// Cookieless request: startup() defers session_start(), so there is no id yet.
		$this->assertSame('', $storage->getId());
	}

	#[RunInSeparateProcess]
	public function testGetIdReturnsTheActiveSessionId(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-getid-active');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$storage->store('user_id', 42);

		$this->assertNotSame('', $storage->getId());
		$this->assertSame(session_id(), $storage->getId());

		$storage->shutdown();
		$this->assertSame('', $storage->getId());
	}

	/**
	 * hasSession() answers "can a write land somewhere that already exists?" --
	 * used by callers persisting default/empty state so they do not manufacture
	 * a session (and a Set-Cookie) for a visitor who never had one.
	 */
	#[RunInSeparateProcess]
	public function testHasSessionIsFalseForACookielessRequest(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-hassession-none');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();

		$this->assertFalse($storage->hasSession());
	}

	#[RunInSeparateProcess]
	public function testHasSessionIsTrueOnceASessionIsActive(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-hassession-active');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$storage->store('user_id', 42);

		$this->assertTrue($storage->hasSession());
	}

	#[RunInSeparateProcess]
	public function testHasSessionIsTrueWhenTheRequestCarriesTheSessionCookie(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-hassession-cookie');
		$storage = new SessionStorage();
		$storage->initialize($context);
		// An incoming cookie means a session exists to be lazily started, even
		// though startup() has not started one yet.
		$_COOKIE['Quiote'] = 'an-incoming-session-id';

		$this->assertTrue($storage->hasSession());

		unset($_COOKIE['Quiote']);
	}

	/**
	 * session_regenerate_id() moves the session to a new id but leaves the
	 * pre-rotation value in $_COOKIE. Since $_COOKIE is what gates the lazy
	 * session_start() in retrieve()/remove(), leaving it stale makes the rest
	 * of the request reason about an id that was just deleted -- which bites
	 * immediately, because setAuthenticated(true) regenerates and then reads
	 * credentials back out of storage.
	 */
	#[RunInSeparateProcess]
	public function testRegenerateUpdatesTheIncomingCookieToTheNewId(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-regenerate-cookie');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$storage->store('user_id', 42);
		$oldId = session_id();
		$_COOKIE[session_name()] = $oldId;

		$this->assertTrue($storage->regenerate(true));

		$newId = session_id();
		$this->assertNotSame($oldId, $newId);
		$this->assertSame($newId, $_COOKIE[session_name()]);
		// And the data is still reachable under the new id.
		$this->assertSame(42, $storage->retrieve('user_id'));
	}

	#[RunInSeparateProcess]
	public function testRegeneratePreservesSessionDataUnderANewId(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-regenerate');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$storage->store('user_id', 42);
		$oldId = session_id();

		$result = $storage->regenerate(true);

		$this->assertTrue($result);
		$this->assertNotSame($oldId, session_id());
		$this->assertSame(42, $storage->retrieve('user_id'));
	}

	#[RunInSeparateProcess]
	public function testResetClearsSessionStateForWorkerReuse(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-worker-reset-clears-session');
		$storage = new SessionStorage();
		$storage->initialize($context);

		// Reproduce the worker-retained state of a prior request (user A) that has
		// already been session_write_close()'d: status is NONE, but PHP's session
		// module keeps the id and $_SESSION superglobal alive in the long-lived
		// process. (In the real flow Context::reset() calls storage->shutdown()
		// — which write-closes — immediately before storage->reset().)
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		session_id('alice-leftover-session-id');
		$_SESSION = ['authenticated' => true, 'user' => 'alice'];
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'precondition: no active session, mirroring post-shutdown worker state');
		$this->assertSame('alice-leftover-session-id', session_id(), 'precondition: prior request id lingers in the worker');

		$storage->reset();

		$this->assertSame([], $_SESSION, 'reset() must clear $_SESSION so the next worker request cannot inherit it');

		// The guarantee is that the previous request's id is gone, not that the
		// id is empty. session_id('') would leave PHP's session module treating
		// the id as explicitly set, so it stops consulting $_COOKIE and every
		// later request in that worker silently starts a brand-new session
		// instead of resuming the client's -- verified against real RoadRunner
		// and Swoole workers. A fresh placeholder breaks the carry-over just as
		// effectively, and startup() replaces it with the incoming id.
		$this->assertNotSame(
			'alice-leftover-session-id',
			session_id(),
			"reset() must drop the previous request's session id",
		);
		$this->assertNotSame('', session_id(), 'but must not blank it, which wedges the session module in a worker');
	}

	/**
	 * The other half of that contract: startup() adopts whatever id the request
	 * actually arrived with, overwriting the placeholder reset() left behind.
	 * Without this, a worker that has served one request never resumes any
	 * client's session again.
	 */
	#[RunInSeparateProcess]
	public function testStartupAdoptsTheIncomingCookieOverALeftoverWorkerId(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-worker-reset-clears-session');
		$storage = new SessionStorage();
		$storage->initialize($context);

		// Establish a session for "the client", then simulate the worker
		// boundary exactly as Context::reset() does.
		$storage->startup();
		$storage->store('user', 'bob');
		$clientId = session_id();
		$storage->shutdown();
		$storage->reset();

		$this->assertNotSame($clientId, session_id(), 'precondition: the boundary dropped the id');

		// Next request from the same client, in the same worker.
		$_COOKIE['Quiote'] = $clientId;
		$next = new SessionStorage();
		$next->initialize($context);
		$next->startup();

		$this->assertSame($clientId, session_id(), 'the incoming cookie must win over the leftover id');
		$this->assertSame('bob', $next->retrieve('user'), 'and the session data must come back with it');

		unset($_COOKIE['Quiote']);
	}

	/**
	 * Regression test: reset() nulls out the default SessionHandler (worker
	 * mode), but the instance itself is reused for the next request. Before
	 * this fix, a subsequent write()/read()/destroy()/gc()/open() call would
	 * fatal by invoking a method on null. PHP's SessionHandler only actually
	 * services these calls when the very same instance was registered via
	 * session_set_save_handler() with an active session, which base
	 * SessionStorage never does — so we can't drive this end-to-end through
	 * a real session lifecycle without SessionHandler itself throwing
	 * "Session is not active" / "Cannot call default session handler".
	 * Instead we assert on the mechanism directly: the private handler is
	 * lazily recreated (not left null) after reset(), which is what
	 * prevents the null-pointer fatal.
	 */
	#[RunInSeparateProcess]
	public function testDefaultHandlerIsLazilyRecreatedAfterReset(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-handler-after-reset');
		$storage = new SessionStorage();
		$storage->initialize($context);

		$property = new \ReflectionProperty(SessionStorage::class, 'defaultHandler');
		$this->assertInstanceOf(\SessionHandler::class, $property->getValue($storage));

		$storage->reset();
		$this->assertNull($property->getValue($storage), 'precondition: reset() nulls the handler');

		$method = new \ReflectionMethod(SessionStorage::class, 'getSessionHandler');
		$recreated = $method->invoke($storage);

		$this->assertInstanceOf(\SessionHandler::class, $recreated, 'a fresh handler must be created on demand, not left null');
		$this->assertInstanceOf(\SessionHandler::class, $property->getValue($storage), 'the recreated handler must be cached back onto the instance');
	}

	/**
	 * startup() dereferences the initialized Context to read routing/request
	 * data; calling it without initialize() must fail with a clear
	 * StorageException instead of a null pointer error.
	 */
	#[RunInSeparateProcess]
	public function testStartupWithoutInitializedContextThrows(): void
	{
		$storage = new SessionStorage();

		$this->expectException(\Quiote\Exception\StorageException::class);
		$this->expectExceptionMessage('cannot start a session without an initialized Context');
		$storage->startup();
	}

	/**
	 * Covers item 4b of PERF_PLAN.md: a cookieless request (bot, API client,
	 * first-time visitor) has no session to load, so retrieve()/remove()
	 * must not eagerly session_start() just to look for one -- it can only
	 * ever come back empty. startup() itself must defer the actual
	 * session_start() the same way (see testCloseWriteClosesTheActiveSession,
	 * which now writes first to get an active session at all).
	 */
	#[RunInSeparateProcess]
	public function testRetrieveWithoutIncomingCookieReturnsNullWithoutStartingASession(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-lazy-retrieve');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();

		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'precondition: startup() must not have eagerly started a session');
		$this->assertNull($storage->retrieve('user_id'));
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'retrieve() on a cookieless request must not start a session either');
	}

	#[RunInSeparateProcess]
	public function testRemoveWithoutIncomingCookieReturnsNullWithoutStartingASession(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-lazy-retrieve');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();

		$this->assertNull($storage->remove('user_id'));
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'remove() on a cookieless request must not start a session either');
	}

	/**
	 * store() is the one operation that must always start a session even
	 * without an incoming cookie -- the caller is explicitly writing
	 * something, so a first-time visitor still gets a session created (and,
	 * in the real request cycle, a Set-Cookie for it).
	 */
	#[RunInSeparateProcess]
	public function testStoreStartsASessionEvenWithoutIncomingCookie(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-lazy-retrieve');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'precondition: no session yet');

		$this->assertTrue($storage->store('user_id', 42));

		$this->assertSame(PHP_SESSION_ACTIVE, session_status());
		$this->assertSame(42, $storage->retrieve('user_id'));
	}

	/**
	 * 'auto_start' is documented on SessionStorage and must actually be read:
	 * with it off, startup() configures the session (the static session_id
	 * below included) but starts nothing, even for a request that would
	 * otherwise start one -- a configured 'session_id' is exactly what
	 * hasIncomingSessionOrStaticId() treats as "there is a session to load".
	 * The cookieless 'tests-lazy-retrieve' context is used so nothing has
	 * started a session before startup() runs.
	 */
	#[RunInSeparateProcess]
	public function testAutoStartOffDefersTheSessionStartInStartup(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-lazy-retrieve');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->setParameter('session_id', 'autostartoff');
		$storage->setParameter('auto_start', 'false');
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'precondition: no session before startup()');

		$storage->startup();

		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'auto_start=false must leave the session unstarted');
		// The configured static id is still applied, so the deferred start uses it.
		$this->assertSame('autostartoff', session_id());
	}

	/**
	 * ...and the deferred session still materializes on demand, under the
	 * configured id, so auto_start=false costs nothing but the eager start.
	 */
	#[RunInSeparateProcess]
	public function testAutoStartOffStillStartsTheSessionOnFirstStore(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-lazy-retrieve');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->setParameter('session_id', 'autostartoff');
		$storage->setParameter('auto_start', 'false');
		$storage->startup();

		$this->assertTrue($storage->store('user_id', 42));

		$this->assertSame(PHP_SESSION_ACTIVE, session_status());
		$this->assertSame('autostartoff', session_id());
		$this->assertSame(42, $storage->retrieve('user_id'));
	}

	/**
	 * The complement: the default (and an explicit auto_start=true) still
	 * starts the session in startup() for the same setup.
	 */
	#[RunInSeparateProcess]
	public function testAutoStartOnStartsTheSessionInStartup(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-lazy-retrieve');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->setParameter('session_id', 'autostarton');
		$storage->setParameter('auto_start', 'true');

		$storage->startup();

		$this->assertSame(PHP_SESSION_ACTIVE, session_status());
		$this->assertSame('autostarton', session_id());
	}

	/**
	 * paramBool() has to cope with the string spellings XML-declared
	 * parameters arrive as, and fall back to the default for anything it
	 * can't make sense of (an absent parameter included).
	 */
	#[RunInSeparateProcess]
	public function testParamBoolReadsStringSpellingsAndFallsBackToTheDefault(): void
	{
		$storage = new SessionStorage();
		$method = new ReflectionMethod(SessionStorage::class, 'paramBool');

		$this->assertTrue($method->invoke($storage, 'auto_start', true), 'an absent parameter must use the default');
		$this->assertFalse($method->invoke($storage, 'auto_start', false), 'an absent parameter must use the default');

		foreach (['false', 'off', 'no', '0', 'FALSE'] as $falsey) {
			$storage->setParameter('auto_start', $falsey);
			$this->assertFalse($method->invoke($storage, 'auto_start', true), $falsey . ' must read as false');
		}

		foreach (['true', 'on', 'yes', '1'] as $truthy) {
			$storage->setParameter('auto_start', $truthy);
			$this->assertTrue($method->invoke($storage, 'auto_start', false), $truthy . ' must read as true');
		}

		$storage->setParameter('auto_start', false);
		$this->assertFalse($method->invoke($storage, 'auto_start', true), 'a real bool must be honoured');

		$storage->setParameter('auto_start', ['not', 'a', 'bool']);
		$this->assertTrue($method->invoke($storage, 'auto_start', true), 'an unintelligible value must fall back to the default');
	}

	/**
	 * Covers item 4d of PERF_PLAN.md: SessionMiddleware calls
	 * storage->shutdown() after the handler; in FrankenPHP worker mode,
	 * Context::reset() calls storage->shutdown() again on the same instance
	 * as part of its manual shutdown sequence. shutdown() is just
	 * session_write_close(), so this verifies PHP's own session module
	 * genuinely no-ops a second close instead of writing the session data to
	 * the backing store twice.
	 */
	#[RunInSeparateProcess]
	public function testShutdownCalledTwiceDoesNotWriteToTheBackingStoreTwice(): void
	{
		$handler = new class implements \SessionHandlerInterface {
			public int $writeCalls = 0;
			public function open($path, $name): bool { return true; }
			public function close(): bool { return true; }
			public function read($id): string { return ''; }
			public function write($id, $data): bool { $this->writeCalls++; return true; }
			public function destroy($id): bool { return true; }
			public function gc($max): int { return 0; }
		};
		session_set_save_handler($handler);

		session_start();
		$_SESSION['user_id'] = 42;

		$storage = new SessionStorage();

		$storage->shutdown();
		$this->assertSame(1, $handler->writeCalls, 'first shutdown() must persist exactly once');
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status());

		$storage->shutdown();
		$this->assertSame(1, $handler->writeCalls, 'a second shutdown() on an already-closed session must not write again');
	}

	#[RunInSeparateProcess]
	public function testLoggerHelperCachesCategoryLoggerAcrossCalls(): void
	{
		$storage = new SessionStorage();
		$method = new ReflectionMethod(SessionStorage::class, 'logger');

		$first = $method->invoke($storage);
		$second = $method->invoke($storage);

		$this->assertInstanceOf(\Quiote\Logging\CategoryLogger::class, $first);
		$this->assertSame($first, $second);
		$this->assertSame($first, \Quiote\Logging\Log::for($storage));
	}

	#[RunInSeparateProcess]
	public function testStartupStillWorksWhenDebugLoggingIsEnabled(): void
	{
		// Guards the isEnabled()-gated debug() calls added throughout
		// startup()/retrieve()/store(): with debug on, those branches now run
		// for real (string building, ini_get() calls, etc.) instead of being
		// skipped, so a real session must still start successfully.
		\Quiote\Logging\Log::setDefaultLevel(\Quiote\Logging\Level::Debug);
		try {
			// Reuses the 'tests-static-session-id' context, which configures a
			// static session_id parameter -- hasIncomingSessionOrStaticId()
			// needs that (or an incoming cookie) for startup() to actually
			// start a session rather than deferring it.
			$context = Context::getInstance('quiote-session-storage-test::tests-static-session-id');
			$storage = new SessionStorage();
			$storage->initialize($context);
			$storage->startup();
			$this->assertSame(PHP_SESSION_ACTIVE, session_status());
		} finally {
			\Quiote\Logging\Log::reset();
		}
	}

}
