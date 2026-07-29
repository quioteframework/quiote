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

    public function getSession(): Session
    {
        return $this->session;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

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

    public function getId(): string
    {
        return $this->session->getId();
    }

    public function regenerate(bool $deleteOld = true): void
    {
        $this->manager->regenerate($this->session, $deleteOld, $this->request);
    }

    public function destroy(): void
    {
        $this->manager->destroy($this->session);
    }
}
