<?php

declare(strict_types=1);

namespace Quiote\Session;

use Throwable;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Opinionated, PSR-7-based session handling: a cookie carrying a session id, and
 * a pluggable SessionPersistenceInterface backend for the data. Deliberately does
 * NOT use PHP's native $_SESSION/session_start()/session_regenerate_id() — those
 * assume a single global session per process, which doesn't compose well with
 * PSR-7 request/response objects or long-running worker runtimes (FrankenPHP,
 * RoadRunner, etc).
 *
 * Session id regeneration (regenerate()) is safe against the classic race where a
 * request already in flight with the pre-regeneration cookie arrives after the
 * old id has been migrated away from: instead of deleting/blanking the old id
 * immediately, it's redirected to the new one for a short grace window (see
 * migrateOld()). Without this, that in-flight request finds a missing/blanked
 * session and silently starts a new anonymous one, which — if its response
 * reaches the browser after the regenerating response's Set-Cookie — makes the
 * user appear logged out right after logging in.
 *
 * Usage: construct one instance per app (it's stateless aside from config), call
 * startFromRequest() at the top of a request to get a Session, mutate it via
 * set()/remove(), call regenerate() on privilege transitions (e.g. login) to
 * defeat session fixation, and persistAndBakeCookies() at the end of the request
 * to save (if dirty) and emit the Set-Cookie header. See SessionMiddleware for a
 * ready-made PSR-15 wiring of this lifecycle.
 */
class SessionManager
{
    private const REDIRECT_KEY = '__quiote_session_redirect_to__';
    private const REDIRECT_AT_KEY = '__quiote_session_redirect_at__';

    private SessionPersistenceInterface $persistence;
    private string $cookieName = 'QSID';
    private int $lifetime = 0;
    private bool $httponly = true;
    private bool $secure = true;
    private ?string $samesite = 'Lax';
    private int $migrationGraceSeconds = 15;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(SessionPersistenceInterface $persistence, array $parameters = [])
    {
        $this->persistence = $persistence;
        if (isset($parameters['cookie_name'])) {
            $this->cookieName = (string)$parameters['cookie_name'];
        }
        if (isset($parameters['session_cookie_lifetime'])) {
            $this->lifetime = (int)$parameters['session_cookie_lifetime'];
        }
        if (isset($parameters['session_cookie_httponly'])) {
            $this->httponly = (bool)$parameters['session_cookie_httponly'];
        }
        if (isset($parameters['session_cookie_secure'])) {
            $this->secure = (bool)$parameters['session_cookie_secure'];
        }
        if (array_key_exists('session_cookie_samesite', $parameters)) {
            $this->samesite = $parameters['session_cookie_samesite'];
        }
        if (isset($parameters['session_migration_grace_seconds'])) {
            $this->migrationGraceSeconds = (int)$parameters['session_migration_grace_seconds'];
        }
    }

    public function startFromRequest(ServerRequestInterface $request): Session
    {
        $cookies = $request->getCookieParams();
        $sid = $cookies[$this->cookieName] ?? null;
        if (is_string($sid) && preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $sid)) {
            $data = $this->persistence->load($sid);
            if ($data !== null) {
                if (isset($data[self::REDIRECT_KEY])) {
                    $resolved = $this->resolveRedirect($data);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                    // Grace window expired or target gone: fall through to a fresh session.
                } else {
                    return new Session($sid, $data, false);
                }
            }
        }
        return new Session($this->generateSid(), [], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveRedirect(array $data): ?Session
    {
        $age = time() - (int)($data[self::REDIRECT_AT_KEY] ?? 0);
        if ($age > $this->migrationGraceSeconds) {
            return null;
        }
        $target = (string)$data[self::REDIRECT_KEY];
        $targetData = $this->persistence->load($target);
        if ($targetData === null || isset($targetData[self::REDIRECT_KEY])) {
            return null;
        }
        return new Session($target, $targetData, false);
    }

    public function persistAndBakeCookies(Session $session, ResponseInterface $response): ResponseInterface
    {
        if ($session->isDirty()) {
            $this->persistence->save($session->getId(), $session->all());
        }
        return $response->withAddedHeader('Set-Cookie', $this->buildCookieHeader($session->getId()));
    }

    /**
     * Persist session data immediately without touching cookie headers. Useful for
     * critical mutations (e.g. right before a privilege transition) to minimize
     * the data-loss window on an abrupt shutdown.
     */
    public function persist(Session $session): void
    {
        if ($session->isDirty()) {
            $this->persistence->save($session->getId(), $session->all());
            $session->markClean();
        }
    }

    /**
     * Regenerate the session id, preserving the session's data. Call this on
     * privilege transitions (e.g. login) to defeat session fixation. When
     * $deleteOld is true, the old id is migrated (not deleted outright) via
     * migrateOld() — see the class docblock for why.
     */
    public function regenerate(Session $session, bool $deleteOld = false): void
    {
        $old = $session->getId();
        $new = $this->generateSid();
        $session->replaceId($new);
        $session->markDirty();
        if ($old !== '' && $old !== $new) {
            // Persist immediately so the new id is loadable right away: a request racing in
            // with the old cookie needs a real row to redirect to. Session stays marked dirty
            // so the normal persistAndBakeCookies()/persist() path still runs later (a
            // harmless, idempotent re-save) — dirty reflects "needs a persist call", not
            // "storage is currently out of sync".
            $this->persistence->save($new, $session->all());
            if ($deleteOld) {
                $this->migrateOld($old, $new);
            }
        }
    }

    /**
     * Replace an old session id's data with a redirect marker to the new one, valid
     * for session_migration_grace_seconds. A request that arrives with the old
     * cookie within that window transparently resolves to the new session instead
     * of finding a blanked/deleted row and silently starting a new anonymous one.
     * After the window elapses the old id stops resolving to anything — which is
     * what actually defeats a fixation attempt.
     */
    public function migrateOld(string $old, string $new): void
    {
        if ($old === '' || $old === $new) {
            return;
        }
        try {
            $this->persistence->save($old, [
                self::REDIRECT_KEY => $new,
                self::REDIRECT_AT_KEY => time(),
            ]);
        } catch (Throwable) {
        }
    }

    public function destroy(Session $session): void
    {
        $old = $session->getId();
        if ($old !== '') {
            $this->persistence->delete($old);
        }
        $session->replaceId($this->generateSid());
        $session->replaceData([]);
        $session->markDirty();
    }

    public function delete(string $sid): void
    {
        if ($sid !== '') {
            $this->persistence->delete($sid);
        }
    }

    private function buildCookieHeader(string $sid): string
    {
        $cookie = $this->cookieName . '=' . $sid;
        if ($this->lifetime > 0) {
            $expire = time() + $this->lifetime;
            $cookie .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', $expire) . '; Max-Age=' . $this->lifetime;
        }
        $cookie .= '; Path=/';
        if ($this->secure) {
            $cookie .= '; Secure';
        }
        if ($this->httponly) {
            $cookie .= '; HttpOnly';
        }
        if ($this->samesite) {
            $cookie .= '; SameSite=' . $this->samesite;
        }
        return $cookie;
    }

    private function generateSid(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
