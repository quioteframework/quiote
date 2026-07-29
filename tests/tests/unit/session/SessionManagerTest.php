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
     * The anonymous-to-authenticated login case: there is nothing in flight
     * worth preserving, so the old id is deleted outright and there is no
     * fixation window at all -- matching session_regenerate_id(true).
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
