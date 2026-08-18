<?php

declare(strict_types=1);

namespace Quiote\Session;

use Throwable;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

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
 * Server-side expiry is available via `session_idle_timeout` and
 * `session_absolute_timeout` (both seconds, both 0/off by default; see
 * {@see hasExpired()}). The cookie's own Max-Age cannot stand in for these --
 * it is a hint to the browser, and an attacker replaying a captured id ignores
 * it -- so without one of them set a stolen session id stays valid for as long
 * as the record survives in storage.
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
    private const REDIRECT_UA_KEY = '__quiote_session_redirect_ua__';
    /** When the session was first created; drives the absolute timeout. */
    private const CREATED_AT_KEY = '__quiote_session_created_at__';
    /** When the session was last seen; drives the idle timeout. */
    private const SEEN_AT_KEY = '__quiote_session_seen_at__';

    private SessionPersistenceInterface $persistence;
    private string $cookieName = 'QSID';
    private int $lifetime = 0;
    private bool $httponly = true;
    private bool $secure = true;
    private ?string $samesite = 'Lax';
    /**
     * How long the pre-regeneration id keeps resolving to the new one. This is
     * a fixation window, so it is kept short and is further narrowed by the
     * one-shot and user-agent checks in resolveRedirect(); see migrateOld().
     */
    private int $migrationGraceSeconds = 5;
    /**
     * Seconds of inactivity after which a session stops resolving, or 0 for no
     * idle timeout. See {@see hasExpired()}.
     */
    private int $idleTimeout = 0;
    /**
     * Seconds after creation at which a session stops resolving regardless of
     * activity, or 0 for no absolute timeout. See {@see hasExpired()}.
     */
    private int $absoluteTimeout = 0;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        SessionPersistenceInterface $persistence,
        array $parameters = [],
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->persistence = $persistence;
        if (isset($parameters['cookie_name']) && (is_string($parameters['cookie_name']) || is_numeric($parameters['cookie_name']))) {
            $this->cookieName = (string)$parameters['cookie_name'];
        }
        if (isset($parameters['session_cookie_lifetime']) && (is_int($parameters['session_cookie_lifetime']) || is_string($parameters['session_cookie_lifetime']))) {
            $this->lifetime = (int)$parameters['session_cookie_lifetime'];
        }
        if (isset($parameters['session_cookie_httponly'])) {
            $this->httponly = (bool)$parameters['session_cookie_httponly'];
        }
        if (isset($parameters['session_cookie_secure'])) {
            $this->secure = (bool)$parameters['session_cookie_secure'];
        }
        if (array_key_exists('session_cookie_samesite', $parameters)) {
            $samesite = $parameters['session_cookie_samesite'];
            if ($samesite === null) {
                $this->samesite = null;
            } elseif (is_string($samesite)) {
                $this->samesite = $samesite;
            } elseif (is_scalar($samesite)) {
                $this->samesite = (string)$samesite;
            }
        }
        if (isset($parameters['session_migration_grace_seconds']) && (is_int($parameters['session_migration_grace_seconds']) || is_string($parameters['session_migration_grace_seconds']))) {
            $this->migrationGraceSeconds = (int)$parameters['session_migration_grace_seconds'];
        }
        if (isset($parameters['session_idle_timeout']) && (is_int($parameters['session_idle_timeout']) || is_string($parameters['session_idle_timeout']))) {
            $this->idleTimeout = max(0, (int)$parameters['session_idle_timeout']);
        }
        if (isset($parameters['session_absolute_timeout']) && (is_int($parameters['session_absolute_timeout']) || is_string($parameters['session_absolute_timeout']))) {
            $this->absoluteTimeout = max(0, (int)$parameters['session_absolute_timeout']);
        }
    }

    /**
     * The name of the cookie this manager reads the session id from and bakes
     * it back onto the response as (`cookie_name`, default `QSID`).
     *
     * Exposed because consumers have to be able to ask "does this request carry
     * a session?" against the name actually in use. Reaching for ext/session's
     * session_name() instead is wrong here: this class deliberately does not use
     * ext/session at all (see the class docblock), so session_name() answers
     * with an unrelated default -- which is exactly how CSRF validation came to
     * exempt every request.
     */
    public function getCookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Resolves the request's session cookie into a {@see Session}, or creates a
     * fresh one.
     *
     * The cookie value must match the generated-id format before storage is even
     * consulted, so a malformed or attacker-supplied id costs no backend lookup.
     * A loaded record is then handled one of three ways: a redirect marker left
     * by a previous rotation resolves to the new id and is deleted on the spot
     * (one-shot, so the old id stops working immediately afterwards); a record
     * past its idle or absolute timeout is deleted; anything else is returned as
     * a not-new session with its activity timestamps touched.
     *
     * When the cookie is missing, malformed, unknown to storage, expired, or its
     * redirect could not be resolved, a new session is returned with a freshly
     * generated id, marked new and clean — which is what keeps an anonymous
     * request from costing a persisted row or a Set-Cookie.
     */
    public function startFromRequest(ServerRequestInterface $request): Session
    {
        $cookies = $request->getCookieParams();
        $sid = $cookies[$this->cookieName] ?? null;
        if (is_string($sid) && preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $sid)) {
            $data = $this->persistence->load($sid);
            if ($data !== null) {
                if (isset($data[self::REDIRECT_KEY])) {
                    $resolved = $this->resolveRedirect($data, $request);
                    if ($resolved !== null) {
                        // One-shot: the legitimate in-flight request has now
                        // consumed the tombstone, so an attacker polling the
                        // fixed id finds nothing on the very next try. This
                        // collapses the fixation window from the full grace
                        // period to a single request.
                        $this->persistence->delete($sid);

                        return $resolved;
                    }
                    // Grace window expired or target gone: fall through to a fresh session.
                } elseif ($this->hasExpired($data)) {
                    // Server-side expiry, so a stolen id stops working on a clock
                    // this process controls. The cookie's own Max-Age cannot do
                    // this job: it is a hint to the browser, and an attacker
                    // replaying a captured id simply does not honour it.
                    $this->persistence->delete($sid);
                } else {
                    return $this->touch(new Session($sid, $data, false));
                }
            }
        }
        // Clean, not dirty: a brand-new anonymous session (bot, API client,
        // first-time visitor) that never gets written to shouldn't cost a
        // persisted row or a Set-Cookie -- see persistAndBakeCookies().
        return new Session($this->generateSid(), [], false, true);
    }

    /**
     * Whether a loaded session has aged out.
     *
     * Two independent limits, both off by default (0):
     *
     *  - `session_idle_timeout` -- seconds since the session was last seen. This
     *    is the one that bounds an abandoned session on a shared machine, and a
     *    captured id lifted from a log or a proxy.
     *  - `session_absolute_timeout` -- seconds since the session was created,
     *    regardless of activity. An idle timeout alone never expires a session
     *    an attacker keeps warm by polling it, so a long-lived stolen id stays
     *    valid indefinitely; this is the ceiling that stops that.
     *
     * Both default off because turning either on logs users out, and how long
     * an application's users should stay signed in is a policy decision this
     * class has no basis for making. A session predating the setting has no
     * timestamps recorded, and is treated as not-yet-expired rather than as
     * infinitely old -- switching the setting on must not sign every current
     * user out at once. {@see touch()} stamps it on its first request.
     *
     * @param      array<string, mixed> $data The loaded session data.
     * @return     bool True if the session must not be resolved.
     */
    private function hasExpired(array $data): bool
    {
        $now = $this->clock->unixTimestamp();

        if ($this->idleTimeout > 0) {
            $seenAt = self::timestamp($data[self::SEEN_AT_KEY] ?? null);
            // A future timestamp means the clock moved backwards (an NTP step, a
            // VM resync) rather than that the session is fresh for longer than
            // it should be. Expiring early is the harmless direction.
            if ($seenAt !== null && ($now - $seenAt < 0 || $now - $seenAt > $this->idleTimeout)) {
                return true;
            }
        }

        if ($this->absoluteTimeout > 0) {
            $createdAt = self::timestamp($data[self::CREATED_AT_KEY] ?? null);
            if ($createdAt !== null && ($now - $createdAt < 0 || $now - $createdAt > $this->absoluteTimeout)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record that the session was seen now, and when it was created if that is
     * not yet known.
     *
     * A no-op unless a timeout is configured: the write marks the session dirty,
     * and dirtying every request would persist a row and refresh a cookie for
     * traffic the lazy-session design deliberately keeps free of both.
     *
     * The idle stamp is only rewritten once a whole second has passed, so
     * several requests within the same second do not each mark the session dirty
     * for a value that would not change.
     */
    private function touch(Session $session): Session
    {
        if ($this->idleTimeout <= 0 && $this->absoluteTimeout <= 0) {
            return $session;
        }

        $now = $this->clock->unixTimestamp();
        if (self::timestamp($session->get(self::CREATED_AT_KEY)) === null) {
            $session->set(self::CREATED_AT_KEY, $now);
        }
        if (self::timestamp($session->get(self::SEEN_AT_KEY)) !== $now) {
            $session->set(self::SEEN_AT_KEY, $now);
        }

        return $session;
    }

    /** A stored timestamp as an int, or null when absent/unusable. */
    private static function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && ctype_digit($value) ? (int)$value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveRedirect(array $data, ServerRequestInterface $request): ?Session
    {
        // Bound to the client that triggered the migration. An attacker polling
        // a fixed id from their own browser will not match, so the grace window
        // only ever helps the request it was created for.
        $boundUa = $data[self::REDIRECT_UA_KEY] ?? null;
        if (is_string($boundUa) && $boundUa !== '' && $boundUa !== $this->userAgentFingerprint($request)) {
            return null;
        }

        $redirectAt = $data[self::REDIRECT_AT_KEY] ?? 0;
        $age = $this->clock->unixTimestamp() - (is_int($redirectAt) || is_string($redirectAt) ? (int)$redirectAt : 0);
        // The clock's unixTimestamp() is wall-clock (CLOCK_REALTIME), not monotonic: NTP steps and,
        // notably, VM/hypervisor clock resyncs (observed on WSL2 under CPU load)
        // can move it backward between the migrateOld() write and this read,
        // producing a negative age. Treat that the same as an expired window
        // rather than as "still fresh" -- erring toward re-expiring a redirect
        // too early is harmless, while erring toward extending it past its
        // intended grace period undermines the fixation defense it exists for.
        if ($age < 0 || $age > $this->migrationGraceSeconds) {
            return null;
        }
        $redirectTarget = $data[self::REDIRECT_KEY];
        if (is_string($redirectTarget)) {
            $target = $redirectTarget;
        } elseif (is_scalar($redirectTarget)) {
            $target = (string)$redirectTarget;
        } else {
            return null;
        }
        $targetData = $this->persistence->load($target);
        if ($targetData === null || isset($targetData[self::REDIRECT_KEY])) {
            return null;
        }
        // The target is subject to the same server-side expiry as any other
        // loaded session: resolving a redirect must not be a way to keep using a
        // session that has aged out.
        if ($this->hasExpired($targetData)) {
            $this->persistence->delete($target);

            return null;
        }
        // touch()ed like any other resolved session, so this request counts as
        // activity against the idle timeout rather than silently not counting.
        return $this->touch(new Session($target, $targetData, false));
    }

    /**
     * Writes a dirty session to storage and returns the response with the
     * session cookie added.
     *
     * The write only happens when the session is dirty, and the timeout
     * timestamps are stamped on just before it. The Set-Cookie is added when the
     * session is dirty or was loaded from storage — an untouched brand-new
     * session gets neither a row nor a cookie, while an existing one has its
     * cookie refreshed on every response for sliding expiration.
     *
     * The session's dirty flag is left as it was; call {@see persist()} instead
     * when the write must also clear it.
     */
    public function persistAndBakeCookies(Session $session, ResponseInterface $response): ResponseInterface
    {
        if ($session->isDirty()) {
            $this->stampBeforeWrite($session);
            $this->persistence->save($session->getId(), $session->all());
        }
        // A brand-new session nothing was ever written to has no data worth
        // the client carrying -- skip the Set-Cookie (and, per above, the
        // persisted row) entirely rather than emitting both for every
        // anonymous/bot/API-client hit. An existing session's cookie is
        // still refreshed on every response (sliding expiration) even when
        // its data didn't change this request.
        if ($session->isDirty() || !$session->isNew()) {
            $response = $response->withAddedHeader('Set-Cookie', $this->buildCookieHeader($session->getId()));
        }
        return $response;
    }

    /**
     * Persist session data immediately without touching cookie headers. Useful for
     * critical mutations (e.g. right before a privilege transition) to minimize
     * the data-loss window on an abrupt shutdown.
     */
    public function persist(Session $session): void
    {
        if ($session->isDirty()) {
            $this->stampBeforeWrite($session);
            $this->persistence->save($session->getId(), $session->all());
            $session->markClean();
        }
    }

    /**
     * Stamp the timeout timestamps onto a session that is about to be written.
     *
     * {@see touch()} covers sessions that were *loaded*, but a brand-new one is
     * never loaded -- it is created empty and, if something writes to it,
     * persisted at the end of the same request. Without this it would reach
     * storage with no creation time, and the absolute timeout would only start
     * counting from its second request.
     *
     * Only ever called on an already-dirty session, so it cannot cause the write
     * it is preparing for.
     */
    private function stampBeforeWrite(Session $session): void
    {
        if ($this->idleTimeout <= 0 && $this->absoluteTimeout <= 0) {
            return;
        }
        $now = $this->clock->unixTimestamp();
        if (self::timestamp($session->get(self::CREATED_AT_KEY)) === null) {
            $session->set(self::CREATED_AT_KEY, $now);
        }
        if (self::timestamp($session->get(self::SEEN_AT_KEY)) === null) {
            $session->set(self::SEEN_AT_KEY, $now);
        }
    }

    /**
     * Regenerate the session id, preserving the session's data.
     *
     * $privilegeTransition is what distinguishes the two reasons to rotate an id,
     * and it changes what happens to the old one:
     *
     *  - true (login, or any anonymous->authenticated step): the old id is DELETED
     *    outright, giving the same zero-length window `session_regenerate_id(true)`
     *    does. This is the anti-fixation guarantee, so it cannot be traded away for
     *    convenience -- an id an attacker planted in the victim's browser must stop
     *    resolving to anything the instant the victim authenticates.
     *  - false (routine rotation): the old id is migrated via {@see migrateOld()},
     *    keeping it resolvable for session_migration_grace_seconds so a request
     *    already in flight with the previous cookie doesn't silently land on a fresh
     *    anonymous session. See the class docblock.
     *
     * This used to be decided by whether the session happened to be empty, on the
     * theory that an empty session meant "nothing in flight worth preserving, so
     * this is the login case". That inverted the guarantee in practice: the CSRF
     * token (and any flash message or locale) lives in the same session, so a real
     * login always found a non-empty session and always took the tombstone path,
     * leaving the fixed id rideable for the whole grace window. The zero-window
     * branch was unreachable exactly where it mattered.
     *
     * @param      Session $session The session to rotate.
     * @param      bool $deleteOld Whether to dispose of the old id at all (false leaves it alone).
     * @param      ?ServerRequestInterface $request Used to bind a migration tombstone; irrelevant on a privilege transition.
     * @param      bool $privilegeTransition True when this rotation is a privilege transition; forces an outright delete.
     */
    public function regenerate(
        Session $session,
        bool $deleteOld = false,
        ?ServerRequestInterface $request = null,
        bool $privilegeTransition = false,
    ): void {
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
                if ($privilegeTransition || $session->all() === []) {
                    $this->persistence->delete($old);
                } else {
                    $this->migrateOld($old, $new, $request);
                }
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
    public function migrateOld(string $old, string $new, ?ServerRequestInterface $request = null): void
    {
        if ($old === '' || $old === $new) {
            return;
        }
        try {
            $marker = [
                self::REDIRECT_KEY => $new,
                self::REDIRECT_AT_KEY => $this->clock->unixTimestamp(),
            ];
            if ($request !== null) {
                $marker[self::REDIRECT_UA_KEY] = $this->userAgentFingerprint($request);
            }
            $this->persistence->save($old, $marker);
        } catch (Throwable $e) {
            // Without the marker the pre-rotation id stops resolving immediately, so a request
            // already in flight with the old cookie is logged out instead of being carried over.
            \Quiote\Logging\Log::for($this)->warning(
                '[SessionManager] could not write the redirect marker for the rotated session; a '
                . 'request in flight with the previous cookie will not be carried over: ' . $e->getMessage()
            );
        }
    }

    /**
     * A cheap binding for the redirect tombstone. Not an authentication
     * control -- a User-Agent is attacker-controlled -- but it costs nothing
     * and removes the trivially opportunistic case where a fixed id is polled
     * from a different client during the grace window.
     */
    private function userAgentFingerprint(ServerRequestInterface $request): string
    {
        $ua = $request->getHeaderLine('User-Agent');

        return hash('sha256', $ua);
    }

    /**
     * Deletes the session from storage and rebinds the handle to a fresh, empty
     * id.
     *
     * Used at logout: the pre-logout id is removed outright with no grace
     * window, so it is neither replayable nor inheritable. The passed
     * {@see Session} stays usable — it keeps the new id, empty data and a dirty
     * flag, so anything written afterwards is persisted under the new id.
     */
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

    /**
     * Removes a session record from storage by id.
     *
     * Takes a bare id rather than a {@see Session}, for callers that only hold
     * one — an administrative "sign this user out everywhere". An empty id is
     * ignored, so no backend call is made for a session that was never persisted.
     */
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
            $expire = $this->clock->unixTimestamp() + $this->lifetime;
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
