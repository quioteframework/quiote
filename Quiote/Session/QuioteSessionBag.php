<?php

declare(strict_types=1);

namespace Quiote\Session;

use Psr\Http\Message\ServerRequestInterface;

/**
 * {@see SessionBagInterface} over the PSR-7-native {@see SessionManager} stack.
 *
 * This is the modern half of the seam. Compared with
 * {@see StorageSessionBag}, four failure modes of the ext/session path stop
 * being possible rather than merely being fixed:
 *
 * - There is no process-global session id, so nothing can leak from one worker
 *   request into the next.
 * - There is no SessionHandlerInterface, so no callback can re-enter the
 *   function that invoked it.
 * - save() is an ordinary write with no relationship to headers_sent(), so a
 *   late write lands instead of silently vanishing.
 * - The cookie rides the PSR-7 response rather than PHP's output layer, so it
 *   works unchanged under a non-SAPI worker runtime.
 *
 * Lifecycle is owned by the middleware that installs this on the context: it
 * calls {@see SessionManager::startFromRequest()} on the way in and
 * {@see SessionManager::persistAndBakeCookies()} on the way out.
 *
 * @since      2.2.0
 */
final class QuioteSessionBag implements SessionBagInterface
{
    public function __construct(
        private readonly SessionManager $manager,
        private readonly Session $session,
        private readonly ?ServerRequestInterface $request = null,
    ) {
    }

    /**
     * Returns the underlying {@see Session} this bag wraps.
     *
     * For code that needs the whole session object — reading every key, or the
     * dirty/new flags — rather than the narrow bag contract.
     */
    public function getSession(): Session
    {
        return $this->session;
    }

    /** {@inheritDoc} */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    /** {@inheritDoc} */
    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    /** Writes through to the underlying session, which marks it dirty so it is persisted at the end of the request. */
    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    /** Removes the key from the underlying session, which marks it dirty so the removal is persisted. */
    public function remove(string $key): void
    {
        $this->session->remove($key);
    }

    /**
     * A brand-new session nothing has been written to yet is reported as
     * absent, which is what keeps a default/empty write -- a logout, a
     * token-derived marker -- from persisting a row and emitting a cookie for
     * a client that never had a session. It matches the guard
     * persistAndBakeCookies() already applies on the way out.
     */
    public function exists(): bool
    {
        return !$this->session->isNew() || $this->session->isDirty();
    }

    /**
     * Returns the session id.
     *
     * Never empty on this implementation: a session id is generated up front
     * even for a request that carried no cookie, so an id here does not by
     * itself mean a session exists in storage — {@see exists()} answers that.
     */
    public function getId(): string
    {
        return $this->session->getId();
    }

    /**
     * Rotates the id via the manager, keeping the session's contents.
     *
     * With $privilegeTransition true the previous id is deleted outright; with
     * it false the manager leaves a short-lived redirect marker so a request
     * already in flight under the old cookie still resolves. The marker is
     * bound to the request this bag was built with, when there is one.
     */
    public function regenerate(bool $deleteOld = true, bool $privilegeTransition = false): void
    {
        $this->manager->regenerate($this->session, $deleteOld, $this->request, $privilegeTransition);
    }

    /**
     * Deletes the stored session and continues under a fresh, empty id.
     *
     * The wrapped {@see Session} instance stays usable and is marked dirty, so
     * anything written after this — a post-logout flash message — is persisted
     * against the new id rather than the discarded one.
     */
    public function destroy(): void
    {
        $this->manager->destroy($this->session);
    }
}
