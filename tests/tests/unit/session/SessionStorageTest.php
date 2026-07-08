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

	#[RunInSeparateProcess]
	public function testCloseWriteClosesTheActiveSession(): void
	{
		$context = Context::getInstance('quiote-session-storage-test::tests-close');
		$storage = new SessionStorage();
		$storage->initialize($context);
		$storage->startup();

		$this->assertTrue($storage->close());
		$this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
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
		$this->assertSame('', session_id(), 'reset() must clear the session id so startup() re-reads the incoming request cookie');
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

}
