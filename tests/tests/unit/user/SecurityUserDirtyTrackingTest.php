<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Quiote\Session\QuioteSessionBag;
use Quiote\Session\SessionManager;
use Quiote\Testing\UnitTestCase;
use Quiote\User\RbacSecurityUser;
use Quiote\User\SecurityUser;

/**
 * Dirty tracking and write-on-change across SecurityUser / RbacSecurityUser,
 * including the behaviour this exists for: an anonymous request must leave no
 * session and no cookie behind.
 *
 * The session-creating cases run against a real SessionManager, because the
 * question they ask -- "was a session actually established?" -- can only be
 * answered by a backend. The rest use an in-memory bag.
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

    private function contextWithBag(string $name, \Quiote\Session\SessionBagInterface $bag): Context
    {
        $context = Context::getInstance($name);
        $context->setSessionBag($bag);

        return $context;
    }

    /**
     * A context whose bag is backed by a real SessionManager, plus the
     * persistence behind it so a test can see what was actually written.
     *
     * @return array{0: Context, 1: InMemorySessionPersistence, 2: QuioteSessionBag}
     */
    private function contextWithRealSession(string $name, bool $withCookie = false): array
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence);

        $request = new ServerRequest('GET', '/');
        if ($withCookie) {
            $persistence->save('an-existing-session-id-1234567890', ['seeded' => true]);
            $request = $request->withCookieParams(['QSID' => 'an-existing-session-id-1234567890']);
        }

        $bag = new QuioteSessionBag($manager, $manager->startFromRequest($request), $request);

        return [$this->contextWithBag($name, $bag), $persistence, $bag];
    }

    // ---------------------------------------------------------------- anonymous

    /**
     * The reproducer for the write amplification: a request that reads the user
     * and touches nothing must not establish a session.
     */
    public function testAnAnonymousRequestCreatesNoSession(): void
    {
        [$context, $persistence, $bag] = $this->contextWithRealSession('user-dirty-test::tests-anonymous');

        $user = $this->rbacUser($context);
        $this->assertFalse($user->isDirty(), 'reading a user is not a mutation');

        // The whole request boundary, exactly as flushRequestState() runs it.
        $user->shutdown();

        $this->assertSame([], $persistence->rows, 'an untouched anonymous request must persist nothing');
        $this->assertFalse($bag->exists(), 'and must not count as having a session');
    }

    /**
     * The counterpart: an anonymous visitor who actually sets a preference does
     * get a session, because setting an attribute is a deliberate write.
     */
    public function testAnAnonymousVisitorSettingAPreferenceDoesGetASession(): void
    {
        [$context, , $bag] = $this->contextWithRealSession('user-dirty-test::tests-preferences');

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAttribute('locale', 'fi_FI');
        $this->assertTrue($user->isDirty());

        $user->shutdown();

        $this->assertTrue($bag->exists(), 'a deliberate write establishes the session');

        $stored = $bag->get('org.quiote.user.User');
        $this->assertIsArray($stored, 'the attributes reached the session');
        $namespaced = $stored[$user->getDefaultNamespace()] ?? null;
        $this->assertIsArray($namespaced);
        $this->assertSame('fi_FI', $namespaced['locale'] ?? null);
    }

    // ------------------------------------------------------------- mutators

    public function testAuthAndCredentialMutatorsMarkDirty(): void
    {
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());

        $user = new SecurityUser();
        $user->initialize($context);
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
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());

        $user = new SecurityUser();
        $user->initialize($context);
        $user->addCredential('photos.list');
        $user->markClean();

        $user->addCredential('photos.list');

        $this->assertFalse($user->isDirty(), 'no change means no write');
    }

    public function testRemovingAnAbsentCredentialDoesNotMarkDirty(): void
    {
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());

        $user = new SecurityUser();
        $user->initialize($context);
        $user->markClean();

        $user->removeCredential('never-granted');

        $this->assertFalse($user->isDirty());
    }

    public function testGrantAndRevokeMarkDirty(): void
    {
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());
        $user = $this->rbacUser($context);
        $this->assertFalse($user->isDirty());

        $user->grantRole('administrator');
        $this->assertTrue($user->isDirty());

        $user->markClean();
        $user->revokeRole('administrator');
        $this->assertTrue($user->isDirty());
    }

    public function testGrantingAnUnknownOrAlreadyHeldRoleDoesNotMarkDirty(): void
    {
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());
        $user = $this->rbacUser($context);

        $user->grantRole('no-such-role');
        $this->assertFalse($user->isDirty(), 'an unknown role is not a change');

        $user->grantRole('administrator');
        $user->markClean();
        $user->grantRole('administrator');
        $this->assertFalse($user->isDirty(), 'an already-held role is not a change');
    }

    public function testRevokingAnAbsentRoleDoesNotMarkDirty(): void
    {
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());
        $user = $this->rbacUser($context);
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
    public function testRebuildingCredentialsFromStoredRolesLeavesTheUserClean(): void
    {
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());

        $first = $this->rbacUser($context);
        $first->setAuthenticated(true);
        $first->grantRole('administrator');
        $first->shutdown();

        // Next request against the same session.
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
        $bag = new InMemorySessionBag();
        $bag->set(SecurityUser::TOKEN_DERIVED_NAMESPACE, true);
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', $bag);

        $user = $this->rbacUser($context);

        $this->assertTrue($user->isTokenDerived());
        $this->assertFalse($user->isDirty(), 'the token-derived early return must also leave the user clean');
    }

    public function testRestoreIdentityFromStoragePreservesTheDirtyFlagInBothDirections(): void
    {
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', new InMemorySessionBag());

        $clean = new SecurityUser();
        $clean->initialize($context);
        $clean->restoreIdentityFromStorage();
        $this->assertFalse($clean->isDirty(), 'restoring values read from the session is not a change');

        $dirty = new SecurityUser();
        $dirty->initialize($context);
        $dirty->setAttribute('k', 'v');
        $dirty->restoreIdentityFromStorage();
        $this->assertTrue($dirty->isDirty(), 'and it must not clear a flag something else set');
    }

    // ---------------------------------------------------------------- writes

    /**
     * markTokenDerived() runs on stateless API requests that carry no session.
     * Writing its marker unconditionally handed every such client a session and
     * a cookie.
     */
    public function testMarkTokenDerivedDoesNotWriteWhenThereIsNoSession(): void
    {
        $bag = new InMemorySessionBag(exists: false);
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', $bag);

        $user = new SecurityUser();
        $user->initialize($context);
        $user->markTokenDerived();

        $this->assertTrue($user->isTokenDerived(), 'the in-memory state still applies to this request');
        $this->assertSame(0, $bag->writes, 'but nothing was written to a session that does not exist');
    }

    public function testLogoutDoesNotWriteWhenThereIsNoSession(): void
    {
        $bag = new InMemorySessionBag(exists: false);
        $context = $this->contextWithBag('user-dirty-test::tests-logout', $bag);

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(false);

        $this->assertSame(0, $bag->writes, 'logging out of nothing creates nothing');
    }

    /**
     * Login is the one write that legitimately establishes a session -- it is
     * how a first-time visitor gets one at all.
     */
    public function testLoginStillEstablishesASessionForAFirstTimeVisitor(): void
    {
        [$context, , $bag] = $this->contextWithRealSession('user-dirty-test::tests-logout');
        $this->assertFalse($bag->exists(), 'precondition: nothing here yet');

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(true);

        $this->assertTrue($bag->exists());
        $this->assertTrue($bag->get(SecurityUser::AUTH_NAMESPACE));
    }

    // ---------------------------------------------------------------- logout

    /**
     * Logging out used to write AUTH=false and stop there, leaving the session
     * id valid and replayable: anyone holding it could keep using it, and a
     * later login on the same id inherited whatever was still in it.
     */
    public function testLogoutInvalidatesTheSessionId(): void
    {
        [$context, , $bag] = $this->contextWithRealSession('user-dirty-test::tests-logout', withCookie: true);

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(true);
        $loggedInId = $bag->getId();
        $this->assertNotSame('', $loggedInId);

        $user->setAuthenticated(false);

        $this->assertNotSame($loggedInId, $bag->getId(), 'the post-logout id must not be the logged-in one');
    }

    public function testLogoutDiscardsTheAuthenticatedSessionContents(): void
    {
        [$context, , $bag] = $this->contextWithRealSession('user-dirty-test::tests-logout', withCookie: true);

        $user = new SecurityUser();
        $user->initialize($context);
        $user->setAuthenticated(true);
        $user->addCredential('photos.list');
        $user->shutdown();
        $this->assertNotEmpty($bag->get(SecurityUser::CREDENTIAL_NAMESPACE));

        $user->setAuthenticated(false);

        $this->assertEmpty(
            $bag->get(SecurityUser::CREDENTIAL_NAMESPACE, []),
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
        $bag = new InMemorySessionBag();
        $bag->set(RbacSecurityUser::ROLES_NAMESPACE, ['administrator']);
        $context = $this->contextWithBag('user-dirty-test::tests-anonymous', $bag);

        $user = $this->rbacUser($context);
        // An authenticated instance that nonetheless ended up with no roles.
        (new ReflectionProperty(SecurityUser::class, 'authenticated'))->setValue($user, true);
        (new ReflectionProperty(RbacSecurityUser::class, 'roles'))->setValue($user, []);
        $user->markDirty();

        $user->shutdown();

        $this->assertSame(
            ['administrator'],
            $bag->get(RbacSecurityUser::ROLES_NAMESPACE),
            'an authenticated user with an empty role set must not clobber stored roles',
        );
    }
}
