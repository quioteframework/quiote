<?php
namespace Quiote\Testing;

use Quiote\Context;
use Quiote\Request\WebRequest;

/**
 * UnitTestCase is the base class for all unit testcases and provides
 * the necessary assertions
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class UnitTestCase extends PhpUnitTestCase implements IUnitTestCase
{
	/**
	 * @var        string the name of the context to use, null for default context
	 */
	protected $contextName = null;
	
	/**
	 * Return the context defined for this test (or the default one).
	 * @return     Context The context instance defined for this test.
	 * @since      1.0.0
	 */
	public function getContext()
	{
		return Context::getInstance($this->contextName);
	}

	/**
	 * Install a real {@see \Quiote\Session\SessionManager} on this test's context,
	 * backed by in-memory persistence.
	 *
	 * Exists because the default `testing` context declares no `session` factory
	 * slot: getSessionManager() answers null there and getSessionBag() answers a
	 * NullSessionBag. Anything whose behaviour depends on a session therefore runs
	 * against a configuration no real application uses unless it asks for this.
	 *
	 * That gap was not hypothetical. Every CSRF test ran without a session manager,
	 * so all of them took the legacy ext/session fallback in
	 * `CsrfManager::sessionCookieName()` and none ever exercised the QSID cookie a
	 * real deployment has -- which is how a total CSRF bypass sat behind a green
	 * suite. Use this, plus {@see assertSessionMechanismConfigured()}, in any suite
	 * whose subject reads or writes a session.
	 *
	 * Dropped again by passing null to `Context::setSessionManager()`, which tests
	 * should do in tearDown so the manager does not leak into later tests in the
	 * same process.
	 *
	 * @param      array<string, mixed> $parameters Passed to the SessionManager constructor
	 *             (`cookie_name`, `session_migration_grace_seconds`, ...). Defaults produce
	 *             the framework's own defaults, i.e. a `QSID` cookie.
	 * @return     \Quiote\Session\SessionManager The installed manager.
	 * @since      3.1.0
	 */
	protected function installTestSessionManager(array $parameters = []): \Quiote\Session\SessionManager
	{
		$manager = new \Quiote\Session\SessionManager(new \InMemorySessionPersistence(), $parameters);
		$this->getContext()->setSessionManager($manager);

		return $manager;
	}

	/**
	 * Fail unless this test's context actually has a session mechanism.
	 *
	 * A guard for suites that only mean anything with a session present: without
	 * it, losing the session slot (or forgetting {@see installTestSessionManager()})
	 * turns the suite green while it silently stops testing its subject.
	 *
	 * @return     void
	 * @since      3.1.0
	 */
	protected function assertSessionMechanismConfigured(): void
	{
		$this->assertNotNull(
			$this->getContext()->getSessionManager(),
			'This suite depends on a session; without one it exercises the sessionless fallback path '
			. 'instead of its subject and proves nothing. Call installTestSessionManager() in setUp(), '
			. 'or configure a "session" factory slot for this context.'
		);
	}

	/**
	 * Convenience factory for PSR-compatible WebRequest instances in tests.
	 * @param array<string,mixed> $parameters runtime parameters to seed.
	 * @param string[] $additionalWhitelist additional parameter keys to whitelist.
	 */
	protected function newWebRequest(array $parameters = [], array $additionalWhitelist = []): WebRequest
	{
		$request = new WebRequest();
		$request->initialize($this->getContext());
		// Use withQueryParams() to put params in intrinsic (query) storage rather than
		// runtimeParameters. This ensures that pruneParametersToValidated() can correctly
		// remove unvalidated parameters (runtimeParameters with validatedKeys bypass pruning).
		if($parameters) {
			$request = $request->withQueryParams($parameters);
		}
		// In unit tests we often bypass the validation manager; whitelist seeded keys and any explicitly provided additional whitelist keys (e.g. export targets for failure scenarios).
		$wl = [];
		if($parameters) { $wl = array_keys($parameters); }
		if($additionalWhitelist) { $wl = array_merge($wl, $additionalWhitelist); }
		if($wl) { $request = $request->enforceValidatedParameters(array_values(array_unique($wl))); }
		return $request;
	}
}