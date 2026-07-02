<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Session\Session;
use Quiote\Session\SessionManager;
use Quiote\Session\SessionPersistenceInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;

final class InMemorySessionPersistence implements SessionPersistenceInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function load(string $sid): ?array
    {
        return $this->rows[$sid] ?? null;
    }

    public function save(string $sid, array $data): void
    {
        $this->rows[$sid] = $data;
    }

    public function delete(string $sid): void
    {
        unset($this->rows[$sid]);
    }
}

class SessionManagerTest extends UnitTestCase
{
    public function testStartFromRequestWithNoCookieCreatesNewSession(): void
    {
        $manager = new SessionManager(new InMemorySessionPersistence());
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(Session::class, $session);
        $this->assertNotEmpty($session->getId());
        $this->assertTrue($session->isDirty());
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

        $manager->regenerate($session, true);
        $newId = $session->getId();

        sleep(1);
        $raced = (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $oldId]);
        $resolved = $manager->startFromRequest($raced);

        $this->assertNotSame($oldId, $resolved->getId());
        $this->assertNotSame($newId, $resolved->getId());
        $this->assertTrue($resolved->isDirty());
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

    public function testPersistAndBakeCookiesSetsCookieHeader(): void
    {
        $manager = new SessionManager(new InMemorySessionPersistence());
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));

        $response = $manager->persistAndBakeCookies($session, new Response());

        $this->assertStringContainsString('QSID=' . $session->getId(), $response->getHeaderLine('Set-Cookie'));
    }
}
