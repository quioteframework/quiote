<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Quiote\Context;
use Quiote\Storage\SessionStorage;
use Quiote\Testing\UnitTestCase;
use Quiote\User\RbacSecurityUser;
use Quiote\User\SecurityUser;

/**
 * Dirty tracking and write-on-change across SecurityUser / RbacSecurityUser,
 * including the behaviour this exists for: an anonymous request must leave no
 * session row and no Set-Cookie behind.
 */
class SecurityUserDirtyTrackingTest extends UnitTestCase
{
    private function rbacUser(Context $context): RbacSecurityUser
    {
        $user = new RbacSecurityUser();
        $user->initialize($context, [
            'definitions_file' => \Quiote\Config\Config::getString('core.config_dir') . '/tests/rbac_definitions.xml',
        ]);

        return $user;
    }

    private function withSessionStorage(string $name): Context
    {
        $context = Context::getInstance($name);
        $storage = new SessionStorage();
        $storage->initialize($context);
        (new ReflectionObject($context))->getProperty('storage')->setValue($context, $storage);

        return $context;
    }

    // ---------------------------------------------------------------- anonymous

    /**
     * The reproducer for the write amplification: a request that reads the user
     * and touches nothing must not create a session.
     */
    #[RunInSeparateProcess]
    public function testAnAnonymousCookielessRequestCreatesNoSession(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-anonymous');
        $context->getStorage()->startup();
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'precondition: no session yet');

        $user = $this->rbacUser($context);
        $this->assertFalse($user->isDirty(), 'reading a user is not a mutation');

        // The whole request boundary, exactly as flushRequestState() runs it.
        $user->shutdown();

        $this->assertNotSame(
            PHP_SESSION_ACTIVE,
            session_status(),
            'an untouched anonymous request must not create a session',
        );
        $this->assertSame('', session_id(), 'and therefore has no id to hand out in a Set-Cookie');
    }

    /**
     * The counterpart the user asked about: an anonymous visitor who actually
     * sets a preference still gets a session, because setting an attribute is a
     * deliberate write.
     */
    #[RunInSeparateProcess]
    public function testAnAnonymousVisitorSettingAPreferenceDoesGetASession(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-preferences');
        $context->getStorage()->startup();

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAttribute('locale', 'fi_FI');

        $this->assertTrue($user->isDirty());

        $user->shutdown();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'a deliberate write creates the session');
        $sessionId = session_id();
        $this->assertIsString($sessionId);
        $this->assertNotSame('', $sessionId);

        // And it survives to the next request.
        $context->getStorage()->shutdown();
        $_SESSION = [];
        session_id($sessionId);
        session_start();

        $fresh = new SecurityUser();
        $fresh->initialize($context);
        $this->assertSame('fi_FI', $fresh->getAttribute('locale'));
    }

    // ------------------------------------------------------------- mutators

    public function testAuthAndCredentialMutatorsMarkDirty(): void
    {
        $user = new SecurityUser();
        $user->initialize($this->getContext());
        $this->assertFalse($user->isDirty());

        $user->addCredential('photos.list');
        $this->assertTrue($user->isDirty());

        $user->markClean();
        $user->removeCredential('photos.list');
        $this->assertTrue($user->isDirty());

        $user->markClean();
        $user->clearCredentials();
        $this->assertTrue($user->isDirty());

        $user->markClean();
        $user->markTokenDerived();
        $this->assertTrue($user->isDirty());
    }

    public function testReAddingAnExistingCredentialDoesNotMarkDirty(): void
    {
        $user = new SecurityUser();
        $user->initialize($this->getContext());
        $user->addCredential('photos.list');
        $user->markClean();

        $user->addCredential('photos.list');

        $this->assertFalse($user->isDirty(), 'no change means no write');
    }

    public function testRemovingAnAbsentCredentialDoesNotMarkDirty(): void
    {
        $user = new SecurityUser();
        $user->initialize($this->getContext());
        $user->markClean();

        $user->removeCredential('never-granted');

        $this->assertFalse($user->isDirty());
    }

    public function testGrantAndRevokeMarkDirty(): void
    {
        $user = $this->rbacUser($this->getContext());
        $this->assertFalse($user->isDirty());

        $user->grantRole('administrator');
        $this->assertTrue($user->isDirty());

        $user->markClean();
        $user->revokeRole('administrator');
        $this->assertTrue($user->isDirty());
    }

    public function testGrantingAnUnknownOrAlreadyHeldRoleDoesNotMarkDirty(): void
    {
        $user = $this->rbacUser($this->getContext());

        $user->grantRole('no-such-role');
        $this->assertFalse($user->isDirty(), 'an unknown role is not a change');

        $user->grantRole('administrator');
        $user->markClean();
        $user->grantRole('administrator');
        $this->assertFalse($user->isDirty(), 'an already-held role is not a change');
    }

    public function testRevokingAnAbsentRoleDoesNotMarkDirty(): void
    {
        $user = $this->rbacUser($this->getContext());
        $user->markClean();

        $user->revokeRole('administrator');

        $this->assertFalse($user->isDirty());
    }

    // ------------------------------------------------------------ rehydration

    /**
     * The sharpest case. RbacSecurityUser::initialize() rebuilds credentials by
     * running clearCredentials() + grantRole() in a loop, both of which mark the
     * user dirty. Without an explicit markClean() at the end, every
     * authenticated request would be dirty and rewrite the session -- defeating
     * the whole mechanism on exactly the traffic that matters most.
     */
    #[RunInSeparateProcess]
    public function testRebuildingCredentialsFromStoredRolesLeavesTheUserClean(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-anonymous');

        $first = $this->rbacUser($context);
        $first->setAuthenticated(true);
        $first->grantRole('administrator');
        $first->shutdown();

        // Next request against the same (still open) session.
        $second = $this->rbacUser($context);

        $this->assertTrue($second->isAuthenticated());
        $this->assertSame(['administrator'], $second->getRoles(), 'precondition: roles were rehydrated');
        $this->assertNotEmpty($second->getCredentials(), 'precondition: credentials were rebuilt');
        $this->assertFalse(
            $second->isDirty(),
            'rehydrating and rebuilding is not a change; a read-only authenticated request must not rewrite the session',
        );
    }

    public function testTokenDerivedInitializeLeavesTheUserClean(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $storage = new MockStorage();
        (new ReflectionObject($context))->getProperty('storage')->setValue($context, $storage);
        $storage->store(SecurityUser::TOKEN_DERIVED_NAMESPACE, true);

        $user = $this->rbacUser($context);

        $this->assertTrue($user->isTokenDerived());
        $this->assertFalse($user->isDirty(), 'the token-derived early return must also leave the user clean');
    }

    public function testRestoreIdentityFromStoragePreservesTheDirtyFlagInBothDirections(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        (new ReflectionObject($context))->getProperty('storage')->setValue($context, new MockStorage());

        $clean = new SecurityUser();
        $clean->initialize($context);
        $clean->restoreIdentityFromStorage();
        $this->assertFalse($clean->isDirty(), 'restoring values read from storage is not a change');

        $dirty = new SecurityUser();
        $dirty->initialize($context);
        $dirty->setAttribute('k', 'v');
        $dirty->restoreIdentityFromStorage();
        $this->assertTrue($dirty->isDirty(), 'and it must not clear a flag something else set');
    }

    // ---------------------------------------------------------------- writes

    /**
     * markTokenDerived() runs on stateless API requests that carry no session
     * cookie. Writing its marker unconditionally handed every such client a
     * session row and a Set-Cookie.
     */
    #[RunInSeparateProcess]
    public function testMarkTokenDerivedDoesNotCreateASessionWhenThereIsNone(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-anonymous');
        $context->getStorage()->startup();

        $user = new SecurityUser();
        $user->initialize($context);
        $user->markTokenDerived();

        $this->assertTrue($user->isTokenDerived(), 'the in-memory state still applies to this request');
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'but no session was manufactured for it');
    }

    #[RunInSeparateProcess]
    public function testLogoutDoesNotCreateASessionWhenThereIsNone(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-logout');
        $context->getStorage()->startup();

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(false);

        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'logging out of nothing creates nothing');
    }

    /**
     * Login is the one write that legitimately creates a session -- it is how a
     * first-time visitor gets one at all.
     */
    #[RunInSeparateProcess]
    public function testLoginStillCreatesASessionForAFirstTimeVisitor(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-logout');
        $context->getStorage()->startup();

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(true);

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotSame('', session_id());
    }

    // ---------------------------------------------------------------- logout

    /**
     * Logging out used to write AUTH=false and stop there, leaving the session
     * id valid and replayable: anyone holding it could keep using it, and a
     * later login on the same id inherited whatever the logged-out session
     * still contained.
     */
    #[RunInSeparateProcess]
    public function testLogoutInvalidatesTheSessionId(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-logout');

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(true);
        $loggedInId = session_id();
        $this->assertNotSame('', $loggedInId);

        $user->setAuthenticated(false);

        $this->assertNotSame($loggedInId, session_id(), 'the post-logout session id must not be the logged-in one');
    }

    #[RunInSeparateProcess]
    public function testLogoutDiscardsTheAuthenticatedSessionContents(): void
    {
        $context = $this->withSessionStorage('user-dirty-test::tests-logout');

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(true);
        $user->addCredential('photos.list');
        $user->shutdown();
        $this->assertNotEmpty($_SESSION[SecurityUser::CREDENTIAL_NAMESPACE] ?? []);

        $user->setAuthenticated(false);

        $this->assertEmpty(
            $_SESSION[SecurityUser::CREDENTIAL_NAMESPACE] ?? [],
            'credentials from the authenticated session must not survive logout',
        );
        $this->assertFalse($user->isAuthenticated());
    }

    // -------------------------------------------------------- anti-clobber

    /**
     * Roles were the only one of the three user keys written with no guard,
     * making them the first thing lost when a user initialized against a
     * session it could not read.
     */
    public function testShutdownDoesNotOverwriteStoredRolesWithAnEmptySet(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $storage = new MockStorage();
        (new ReflectionObject($context))->getProperty('storage')->setValue($context, $storage);
        $storage->store(RbacSecurityUser::ROLES_NAMESPACE, ['administrator']);

        $user = $this->rbacUser($context);
        // An authenticated instance that nonetheless ended up with no roles.
        (new ReflectionProperty(SecurityUser::class, 'authenticated'))->setValue($user, true);
        (new ReflectionProperty(RbacSecurityUser::class, 'roles'))->setValue($user, []);
        $user->markDirty();

        $user->shutdown();

        $this->assertSame(
            ['administrator'],
            $storage->retrieve(RbacSecurityUser::ROLES_NAMESPACE),
            'an authenticated user with an empty role set must not clobber stored roles',
        );
    }
}
