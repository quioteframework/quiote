<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Session\Session;
use Quiote\Session\SessionManager;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;

// InMemorySessionPersistence lives in tests/lib so the session-bag suites can
// use it too, rather than depending on this file happening to be loaded.

class SessionManagerTest extends UnitTestCase
{
    public function testStartFromRequestWithNoCookieCreatesNewCleanSession(): void
    {
        $manager = new SessionManager(new InMemorySessionPersistence());
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(Session::class, $session);
        $this->assertNotEmpty($session->getId());
        // Clean, not dirty: a fresh anonymous session that's never written to
        // must not cost a persisted row or a Set-Cookie -- see
        // testPersistAndBakeCookiesSkipsPersistAndCookieForUntouchedNewSession.
        $this->assertFalse($session->isDirty());
        $this->assertSame([], $session->all());
    }

    public function testStartFromRequestRestoresKnownSession(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('an-existing-session-id-1234567890', ['user_id' => 42]);
        $manager = new SessionManager($persistence);

        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'an-existing-session-id-1234567890']);
        $session = $manager->startFromRequest($request);

        $this->assertSame('an-existing-session-id-1234567890', $session->getId());
        $this->assertSame(42, $session->get('user_id'));
        $this->assertFalse($session->isDirty());
    }

    // --- Server-side expiry -------------------------------------------------
    //
    // The session cookie's own Max-Age cannot do this job: it is a hint to the
    // browser, and an attacker replaying a captured id simply ignores it. Without
    // a server-side limit a stolen id stays valid for as long as the record
    // survives in storage.

    private const SEEN_AT = '__quiote_session_seen_at__';
    private const CREATED_AT = '__quiote_session_created_at__';

    public function testIdleTimeoutExpiresAnInactiveSession(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('idle-session-id-1234567890abcd', [
            'user_id' => 42,
            self::SEEN_AT => time() - 3600,
        ]);
        $manager = new SessionManager($persistence, ['session_idle_timeout' => 900]);

        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'idle-session-id-1234567890abcd']);
        $session = $manager->startFromRequest($request);

        $this->assertNotSame('idle-session-id-1234567890abcd', $session->getId(), 'a fresh session, not the expired one');
        $this->assertSame([], $session->all());
        $this->assertNull($persistence->load('idle-session-id-1234567890abcd'), 'the expired record must be dropped');
    }

    public function testIdleTimeoutLeavesARecentlySeenSessionAlone(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('active-session-id-1234567890ab', [
            'user_id' => 42,
            self::SEEN_AT => time() - 10,
        ]);
        $manager = new SessionManager($persistence, ['session_idle_timeout' => 900]);

        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'active-session-id-1234567890ab']);
        $session = $manager->startFromRequest($request);

        $this->assertSame('active-session-id-1234567890ab', $session->getId());
        $this->assertSame(42, $session->get('user_id'));
    }

    /**
     * An idle timeout alone never expires a session an attacker keeps warm by
     * polling it, so a long-lived stolen id stays valid indefinitely. The
     * absolute limit is the ceiling that stops that.
     */
    public function testAbsoluteTimeoutExpiresAnActiveButOldSession(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('old-session-id-1234567890abcd', [
            'user_id' => 42,
            self::SEEN_AT => time(),
            self::CREATED_AT => time() - 90000,
        ]);
        $manager = new SessionManager($persistence, [
            'session_idle_timeout' => 900,
            'session_absolute_timeout' => 86400,
        ]);

        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'old-session-id-1234567890abcd']);
        $session = $manager->startFromRequest($request);

        $this->assertNotSame('old-session-id-1234567890abcd', $session->getId());
        $this->assertSame([], $session->all());
    }

    public function testNoTimeoutsConfiguredMeansNoExpiryAndNoExtraWrites(): void
    {
        // The default. A session with no timestamps and no configured limits must
        // behave exactly as before -- in particular it must stay clean, or every
        // request would persist a row and refresh a cookie.
        $persistence = new InMemorySessionPersistence();
        $persistence->save('ancient-session-id-1234567890', ['user_id' => 42, self::SEEN_AT => 1]);
        $manager = new SessionManager($persistence);

        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'ancient-session-id-1234567890']);
        $session = $manager->startFromRequest($request);

        $this->assertSame('ancient-session-id-1234567890', $session->getId());
        $this->assertFalse($session->isDirty());
    }

    /**
     * Turning a timeout on must not sign every current user out at once: a
     * session stored before the setting existed has no timestamps, so it is
     * treated as not-yet-expired and stamped on this request instead.
     */
    public function testASessionWithoutTimestampsIsAdoptedRatherThanExpired(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('pre-existing-session-id-12345', ['user_id' => 42]);
        $manager = new SessionManager($persistence, ['session_idle_timeout' => 900]);

        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'pre-existing-session-id-12345']);
        $session = $manager->startFromRequest($request);

        $this->assertSame('pre-existing-session-id-12345', $session->getId());
        $this->assertSame(42, $session->get('user_id'));
        $this->assertIsInt($session->get(self::SEEN_AT), 'the activity stamp is recorded on adoption');
        $this->assertTrue($session->isDirty(), 'so it must be written back');
    }

    /**
     * A brand-new session is never loaded, so touch() cannot stamp it. Without
     * stamping at write time it would reach storage with no creation time and the
     * absolute timeout would only start counting from its second request.
     */
    public function testANewSessionIsStampedWhenItIsFirstWritten(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_absolute_timeout' => 86400]);

        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $session->set('user_id', 42);
        $manager->persistAndBakeCookies($session, new Response());

        $stored = $persistence->load($session->getId());
        $this->assertIsArray($stored);
        $this->assertIsInt($stored[self::CREATED_AT] ?? null, 'creation time recorded on the first write');
    }

    public function testAnUntouchedNewSessionIsStillNotPersistedWithTimeoutsOn(): void
    {
        // The lazy-session guarantee must survive the feature: a bot or API hit
        // that writes nothing costs neither a row nor a Set-Cookie.
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_idle_timeout' => 900]);

        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $response = $manager->persistAndBakeCookies($session, new Response());

        $this->assertFalse($session->isDirty());
        $this->assertNull($persistence->load($session->getId()));
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    /**
     * time() is wall-clock: an NTP step or a VM resync can move it backwards, so
     * a stamp can read as being in the future. Expiring early is harmless;
     * treating it as "fresh for longer than configured" would undermine the limit.
     */
    public function testAFutureTimestampExpiresRatherThanExtending(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('skewed-session-id-1234567890a', [
            'user_id' => 42,
            self::SEEN_AT => time() + 100000,
        ]);
        $manager = new SessionManager($persistence, ['session_idle_timeout' => 900]);

        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'skewed-session-id-1234567890a']);
        $session = $manager->startFromRequest($request);

        $this->assertNotSame('skewed-session-id-1234567890a', $session->getId());
    }

    public function testRegeneratePreservesDataUnderNewId(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        $session->set('user_id', 7);

        $manager->regenerate($session, true);

        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame(7, $session->get('user_id'));
    }

    public function testRegenerateWithDeleteOldRedirectsInsteadOfDeletingImmediately(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        $session->set('user_id', 7);

        $manager->regenerate($session, true);
        $newId = $session->getId();

        // Old row is repurposed as a redirect marker, not deleted outright.
        $this->assertNotNull($persistence->load($oldId));

        // A request racing in with the old cookie resolves transparently to the new session.
        $raced = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $resolved = $manager->startFromRequest($raced);

        $this->assertSame($newId, $resolved->getId());
        $this->assertSame(7, $resolved->get('user_id'));
        $this->assertFalse($resolved->isDirty());
    }

    public function testRegenerateRedirectDoesNotResolveAfterGraceWindowExpires(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 0]);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        // A session with contents: regenerating an *empty* one deletes the old
        // id outright rather than leaving a redirect marker, so there would be
        // no grace window here to expire.
        $session->set('user_id', 7);

        $manager->regenerate($session, true);
        $newId = $session->getId();

        // Simulate the grace window having elapsed by rewinding the stored
        // redirect timestamp, rather than sleep()-ing across a real second
        // boundary: time() is wall-clock, not monotonic, so under CPU
        // contention (e.g. the full suite, or a VM clock resync) it can jump
        // in either direction between the write above and a later read here,
        // making a real-time sleep()-based assertion flaky by construction.
        $this->rewindStoredRedirectTimestamp($persistence, $oldId, -10);

        $raced = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $resolved = $manager->startFromRequest($raced);

        $this->assertNotSame($oldId, $resolved->getId());
        $this->assertNotSame($newId, $resolved->getId());
        // A brand-new fallback session (the redirect didn't resolve) is
        // clean, not dirty -- see testStartFromRequestWithNoCookieCreatesNewCleanSession.
        $this->assertFalse($resolved->isDirty());
    }

    public function testRegenerateRedirectDoesNotResolveWhenClockJumpsBackward(): void
    {
        // Defends the fix for a wall-clock-skew bug: if time() ever reports an
        // earlier value than when the redirect marker was written (NTP step,
        // VM clock resync, ...), age = time() - storedAt goes negative. That
        // must NOT be treated as "still within the grace window" -- doing so
        // would let a fixation-defeating redirect marker resolve indefinitely
        // whenever the clock happens to skew backward.
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 15]);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        // See the note in the grace-expiry test: an empty session leaves no
        // redirect marker to skew the timestamp of.
        $session->set('user_id', 7);

        $manager->regenerate($session, true);
        $newId = $session->getId();

        // Push the stored timestamp into the future, which is what a backward
        // clock jump on the *read* side looks like from the write side's
        // perspective: time() - storedAt < 0.
        $this->rewindStoredRedirectTimestamp($persistence, $oldId, 30);

        $raced = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $resolved = $manager->startFromRequest($raced);

        $this->assertNotSame($oldId, $resolved->getId());
        $this->assertNotSame($newId, $resolved->getId());
        // A brand-new fallback session (the redirect didn't resolve) is
        // clean, not dirty -- see testStartFromRequestWithNoCookieCreatesNewCleanSession.
        $this->assertFalse($resolved->isDirty());
    }

    /**
     * Adjusts the timestamp field of a persisted redirect marker by $deltaSeconds
     * without depending on SessionManager's private storage-key constants: the
     * marker row written by migrateOld() has exactly one string field (the
     * target id) and one int field (the timestamp), so the int field is
     * unambiguous.
     */
    private function rewindStoredRedirectTimestamp(
        InMemorySessionPersistence $persistence,
        string $sid,
        int $deltaSeconds
    ): void {
        $row = $persistence->rows[$sid] ?? null;
        $this->assertIsArray($row, 'Expected a persisted redirect marker row for ' . $sid);
        foreach ($row as $key => $value) {
            if (is_int($value)) {
                $row[$key] = $value + $deltaSeconds;
            }
        }
        $persistence->rows[$sid] = $row;
    }

    public function testDestroyDeletesOldRowImmediately(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        $session->set('user_id', 1);
        $manager->persist($session);

        $manager->destroy($session);

        $this->assertNull($persistence->load($oldId));
        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame([], $session->all());
        $this->assertTrue($session->isDirty());
    }

    public function testPersistAndBakeCookiesSetsCookieHeaderWhenSessionWasWrittenTo(): void
    {
        $manager = new SessionManager(new InMemorySessionPersistence());
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $session->set('user_id', 42);

        $response = $manager->persistAndBakeCookies($session, new Response());

        $this->assertStringContainsString('QSID=' . $session->getId(), $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * Covers item 4c of PERF_PLAN.md: a brand-new, never-written-to session
     * (the common case for bots/API clients/first-time visitors) must not
     * cost a persisted row or a Set-Cookie -- there's no session for the
     * client to carry yet.
     */
    public function testPersistAndBakeCookiesSkipsPersistAndCookieForUntouchedNewSession(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));

        $response = $manager->persistAndBakeCookies($session, new Response());

        $this->assertNull($persistence->load($session->getId()));
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    /**
     * An existing session's cookie is still refreshed (sliding expiration)
     * on every response even when nothing changed this request -- only the
     * DB/persistence write is skipped, not the cookie.
     */
    public function testPersistAndBakeCookiesRefreshesCookieForUnchangedExistingSession(): void
    {
        $persistence = new InMemorySessionPersistence();
        $persistence->save('an-existing-session-id-1234567890', ['user_id' => 42]);
        $manager = new SessionManager($persistence);
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => 'an-existing-session-id-1234567890']);
        $session = $manager->startFromRequest($request);
        $this->assertFalse($session->isDirty(), 'precondition: nothing changed this request');

        $response = $manager->persistAndBakeCookies($session, new Response());

        $this->assertStringContainsString('QSID=an-existing-session-id-1234567890', $response->getHeaderLine('Set-Cookie'));
    }

    // ------------------------------------------------- fixation hardening

    /**
     * The grace window exists to rescue a request already in flight with the
     * pre-regeneration cookie, and that is a single request. Leaving the
     * tombstone resolvable for the rest of the window hands an attacker who
     * fixed the old id a way in; consuming it on first use collapses the
     * window to exactly the one request it was created for.
     */
    public function testARedirectMarkerResolvesOnlyOnce(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 300]);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        $session->set('user_id', 7);
        $manager->regenerate($session, true);
        $newId = $session->getId();

        $raced = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $first = $manager->startFromRequest($raced);
        $this->assertSame($newId, $first->getId(), 'the in-flight request is still rescued');

        // Second attempt on the same id, well inside the grace window.
        $second = $manager->startFromRequest($raced);

        $this->assertNotSame($newId, $second->getId(), 'a replay must not reach the regenerated session');
        $this->assertNull($persistence->load($oldId), 'the tombstone is consumed, not left lying around');
    }

    /**
     * The tombstone is bound to the client that caused the migration, so it
     * cannot be ridden from a different browser during the window.
     */
    public function testARedirectMarkerDoesNotResolveForADifferentUserAgent(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 300]);
        $victim = (new ServerRequest('GET', '/'))->withHeader('User-Agent', 'VictimBrowser/1.0');
        $session = $manager->startFromRequest($victim);
        $oldId = $session->getId();
        $session->set('user_id', 7);

        $manager->regenerate($session, true, $victim);
        $newId = $session->getId();

        $attacker = (new ServerRequest('GET', '/'))
            ->withHeader('User-Agent', 'AttackerBrowser/9.9')
            ->withCookieParams(['QSID' => $oldId]);
        $resolved = $manager->startFromRequest($attacker);

        $this->assertNotSame($newId, $resolved->getId());
        $this->assertNotSame($oldId, $resolved->getId());
    }

    public function testARedirectMarkerStillResolvesForTheSameUserAgent(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 300]);
        $client = (new ServerRequest('GET', '/'))->withHeader('User-Agent', 'VictimBrowser/1.0');
        $session = $manager->startFromRequest($client);
        $oldId = $session->getId();
        $session->set('user_id', 7);

        $manager->regenerate($session, true, $client);
        $newId = $session->getId();

        $raced = $client->withCookieParams(['QSID' => $oldId]);
        $resolved = $manager->startFromRequest($raced);

        $this->assertSame($newId, $resolved->getId());
        $this->assertSame(7, $resolved->get('user_id'));
    }

    /**
     * A privilege transition (login) deletes the old id outright, so there is no
     * fixation window at all -- matching session_regenerate_id(true) -- even
     * though the session holds data.
     *
     * This is the regression test for the bug that made emptiness the
     * discriminator: a real login session always holds *something* (the CSRF
     * token at minimum, plus any flash or locale state), so it always took the
     * tombstone path and left the id an attacker had fixed rideable for the whole
     * grace window. The "no fixation window" guarantee was unreachable exactly
     * where it was needed.
     */
    public function testAPrivilegeTransitionDeletesTheOldIdEvenWithSessionData(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 300]);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        // Exactly the shape a real login has: the CSRF token is already in the
        // session, because the login form's token had to validate to get here.
        $session->set('org.quiote.csrf.quiote_csrf', 'a-token');
        $persistence->save($oldId, $session->all());

        $manager->regenerate($session, true, null, privilegeTransition: true);
        $newId = $session->getId();

        $this->assertNull($persistence->load($oldId), 'a privilege transition leaves no tombstone to ride');

        $fixated = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $this->assertNotSame(
            $newId,
            $manager->startFromRequest($fixated)->getId(),
            'the fixed id must not resolve to the freshly authenticated session',
        );
    }

    /**
     * Without the privilege-transition flag a data-carrying session still gets
     * the grace-window tombstone: that is the routine-rotation case, where a
     * request already in flight with the previous cookie must not silently land
     * on a fresh anonymous session.
     */
    public function testRoutineRotationStillMigratesADataCarryingSession(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 300]);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        $session->set('cart_id', 42);

        $manager->regenerate($session, true);
        $newId = $session->getId();

        $raced = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $this->assertSame($newId, $manager->startFromRequest($raced)->getId(), 'the in-flight request is still rescued');
    }

    /**
     * An empty session needs no tombstone either way -- there is nothing in
     * flight worth preserving.
     */
    public function testRegeneratingAnEmptySessionDeletesTheOldIdOutright(): void
    {
        $persistence = new InMemorySessionPersistence();
        $manager = new SessionManager($persistence, ['session_migration_grace_seconds' => 300]);
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $oldId = $session->getId();
        $persistence->save($oldId, []);

        $manager->regenerate($session, true);
        $newId = $session->getId();

        $this->assertNull($persistence->load($oldId), 'no tombstone for a session that held nothing');

        $raced = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $this->assertNotSame($newId, $manager->startFromRequest($raced)->getId());
    }
}
