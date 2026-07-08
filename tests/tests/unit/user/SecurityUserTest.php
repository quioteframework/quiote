<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Quiote\Testing\UnitTestCase;
use Quiote\User\SecurityUser;
use Quiote\Context;

class SampleSecurityUser extends SecurityUser
{
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		parent::initialize($context, $parameters);
		$this->context = $context;
		$this->attributes = [];
	}
}

class SampleIdentityRestoringUser extends SecurityUser
{
	protected const CORE_IDENTITY_KEYS = ['legacy_user_id'];

	protected $storageNamespace = 'org.quiote.user.SampleIdentityRestoringUser';
}


class SecurityUserTest extends UnitTestCase
{
	private SampleSecurityUser $_u;

	#[\Override]
    public function setUp(): void
	{
		$this->_u = new SampleSecurityUser();
		$this->_u->initialize($this->getContext());
		// The authenticated flag and credentials live in the shared storage/session,
		// so a prior test that authenticated a user (e.g. setAuthenticated(true) in
		// the dispatch/slot middleware tests) would otherwise leak in and make this
		// fresh user report isAuthenticated()===true. Establish a clean baseline.
		$this->_u->setAuthenticated(false);
		$this->_u->clearCredentials();
	}

	public function testaddCredential(): void
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('test1');
		$this->assertTrue($this->_u->hasCredentials('test1'));
		$this->_u->addCredential('test2');
		$this->assertTrue($this->_u->hasCredentials('test2'));
	}
	
	public function testhasCredentials(): void
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('test1');
		$this->_u->addCredential('test2');
		$this->_u->addCredential('test3');
		$this->_u->addCredential('test4');
		$this->assertTrue($this->_u->hasCredentials('test1'));
		$this->assertTrue($this->_u->hasCredentials(['test2', 'test3']));
		$this->assertTrue($this->_u->hasCredentials(['test1', ['test2', 'test3']]));
		$this->assertTrue($this->_u->hasCredentials(['test1', ['test2', 'test5']]));
		$this->assertFalse($this->_u->hasCredentials('test5'));
		$this->assertFalse($this->_u->hasCredentials(['test2', 'test5']));
		$this->assertFalse($this->_u->hasCredentials(['test5', ['test2', 'test3']]));
		$this->assertFalse($this->_u->hasCredentials(['test1', ['test5', 'test6']]));
	}
	
	public function teststrictCredentialComparison(): void
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('0');
		$this->assertTrue($this->_u->hasCredentials('0'));
		$this->assertFalse($this->_u->hasCredentials(0));
		$this->assertFalse($this->_u->hasCredentials(false));
	}

	public function testRemoveCredential(): void
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('test1');
		$this->_u->addCredential('test2');
		$this->_u->addCredential('test3');
		$this->assertTrue($this->_u->hasCredentials(['test1', 'test2', 'test3']));
		$this->_u->removeCredential('test2');
		$this->assertTrue($this->_u->hasCredentials(['test3', 'test1']));
		$this->assertFalse($this->_u->hasCredentials(['test1', 'test2', 'test3']));
		$this->assertFalse($this->_u->hasCredentials(['test2']));
		$this->_u->removeCredential('test1');
		$this->assertTrue($this->_u->hasCredentials(['test3']));
		$this->assertFalse($this->_u->hasCredentials(['test1']));
		$this->_u->removeCredential('test3');
		$this->assertFalse($this->_u->hasCredentials(['test3']));
	}

	public function testSetIsAuthenticated(): void
	{
		$u = $this->_u;
		$this->assertFalse($u->isAuthenticated());
		$u->setAuthenticated(1);
		$this->assertFalse($u->isAuthenticated());
		$u->setAuthenticated(true);
		$this->assertTrue($u->isAuthenticated());
		$u->setAuthenticated(1);
		$this->assertFalse($u->isAuthenticated());
		$u->setAuthenticated(true);
		$this->assertTrue($u->isAuthenticated());
		$u->setAuthenticated(false);
		$this->assertFalse($u->isAuthenticated());
	}

	public function testGetCredentialsReflectsAddAndRemove(): void
	{
		$u = $this->_u;
		$u->clearCredentials();
		$this->assertSame([], $u->getCredentials());

		$u->addCredential('test1');
		$u->addCredential('test2');
		$this->assertSame(['test1', 'test2'], $u->getCredentials());

		$u->removeCredential('test1');
		// removeCredential() doesn't reindex the underlying array.
		$this->assertSame([1 => 'test2'], $u->getCredentials());
	}

	public function testResetClearsAuthenticationCredentialsAndContext(): void
	{
		$u = $this->_u;
		$u->setAuthenticated(true);
		$u->addCredential('test1');

		$u->reset();

		$this->assertFalse($u->isAuthenticated());
		$this->assertNull($u->getCredentials());
	}

	public function testMarkTokenDerivedIsReflectedImmediately(): void
	{
		$u = $this->_u;
		$this->assertFalse($u->isTokenDerived());

		$u->markTokenDerived();
		$this->assertTrue($u->isTokenDerived());

		$u->markTokenDerived(false);
		$this->assertFalse($u->isTokenDerived());
	}

	#[RunInSeparateProcess]
	public function testTokenDerivedCredentialsAreNotRehydratedFromStaleSession(): void
	{
		// NullStorage (the default test storage) discards everything, so
		// persistence across separate User instances needs a real,
		// dedicated (SessionStorage-backed) context -- see factories.xml.
		$context = Context::getInstance('security-user-test::tests-token-derived-persistence');

		$u = new SampleSecurityUser();
		$u->initialize($context);
		$u->setAuthenticated(false);
		$u->clearCredentials();
		$u->addCredential('stale_session_credential');
		$u->markTokenDerived();

		// Simulate the next request: a fresh instance re-reads persisted storage.
		$fresh = new SampleSecurityUser();
		$fresh->initialize($context);

		$this->assertTrue($fresh->isTokenDerived());
		$this->assertSame([], $fresh->getCredentials());
	}

	#[RunInSeparateProcess]
	public function testSetAuthenticatedFalseClearsTokenDerivedMarker(): void
	{
		$context = Context::getInstance('security-user-test::tests-token-derived-clear');

		$u = new SampleSecurityUser();
		$u->initialize($context);
		$u->markTokenDerived();
		$this->assertTrue($u->isTokenDerived());

		$u->setAuthenticated(false);

		$this->assertFalse($u->isTokenDerived());

		$fresh = new SampleSecurityUser();
		$fresh->initialize($context);
		$this->assertFalse($fresh->isTokenDerived());
	}

	public function testResetClearsTokenDerivedMarker(): void
	{
		$this->_u->markTokenDerived();
		$this->_u->reset();

		$this->assertFalse($this->_u->isTokenDerived());
	}

	public function testRestoreIdentityFromStorageIsNoOpWithoutCoreIdentityKeys(): void
	{
		// Base SecurityUser declares no CORE_IDENTITY_KEYS; calling the hook
		// must be a safe no-op rather than an error.
		$this->_u->restoreIdentityFromStorage();

		$this->assertFalse($this->_u->hasAttribute('legacy_user_id'));
	}

	#[RunInSeparateProcess]
	public function testRestoreIdentityFromStorageRepopulatesDeclaredKeysAfterColdStart(): void
	{
		$context = Context::getInstance('security-user-test::tests-restore-identity');

		$u = new SampleIdentityRestoringUser();
		$u->initialize($context);
		$u->setAttribute('legacy_user_id', 42);
		$u->persistAttributesImmediate(['legacy_user_id']);

		// Simulate a worker cold start via the unserialize-style restoreContext()
		// path (not initialize(), which already re-reads storage on its own):
		// a fresh instance with no attributes loaded yet.
		$cold = new SampleIdentityRestoringUser();
		$cold->restoreContext($context);
		$this->assertFalse($cold->hasAttribute('legacy_user_id'));

		$cold->restoreIdentityFromStorage();

		$this->assertTrue($cold->hasAttribute('legacy_user_id'));
		$this->assertSame(42, $cold->getAttribute('legacy_user_id'));
	}

	#[RunInSeparateProcess]
	public function testRestoreIdentityFromStorageDoesNotOverwriteAlreadySetAttribute(): void
	{
		$context = Context::getInstance('security-user-test::tests-restore-identity');

		$u = new SampleIdentityRestoringUser();
		$u->initialize($context);
		$u->setAttribute('legacy_user_id', 42);
		$u->persistAttributesImmediate(['legacy_user_id']);

		$u->setAttribute('legacy_user_id', 99);
		$u->restoreIdentityFromStorage();

		$this->assertSame(99, $u->getAttribute('legacy_user_id'));
	}

}
?>