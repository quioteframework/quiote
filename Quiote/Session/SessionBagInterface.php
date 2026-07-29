<?php

declare(strict_types=1);

namespace Quiote\Session;

/**
 * The narrow per-session key/value contract everything that needs session state
 * talks to: the User hierarchy, CSRF token storage, OIDC state, and application
 * code.
 *
 * Quiote carries two session mechanisms. The original one is the `storage`
 * factory slot ({@see \Quiote\Storage\Storage} and its ext/session-backed
 * subclasses), which the User hierarchy was hard-wired to; the newer one is
 * {@see SessionManager} over {@see SessionPersistenceInterface}, which is
 * PSR-7-native and safe under long-lived worker runtimes. They were disjoint --
 * an application running both got two independent sessions and two cookies.
 *
 * This interface is the single seam between them. Consumers depend on it rather
 * than on either mechanism, so which one backs a request becomes configuration
 * instead of a rewrite. It is deliberately small: everything the existing
 * consumers actually used, plus the two operations they previously reached for
 * with `method_exists()` probes that could never succeed.
 *
 * Implementations: {@see StorageSessionBag} over the legacy `Storage` slot.
 *
 * @since      2.1.0
 */
interface SessionBagInterface
{
    /**
     * Read a value, or $default when the key is absent.
     *
     * Note the normalization this hides: the legacy storages disagree on the
     * "missing" sentinel -- SessionStorage returns null while NullStorage
     * returns false -- and consumers only survived that through loose
     * comparison. Implementations must return $default for both.
     */
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Whether a write can land in a session that already exists, rather than
     * manufacturing one for a client that has none.
     *
     * Callers persisting default or empty state -- a logout, a token-derived
     * identity -- consult this so an anonymous or stateless request does not
     * acquire a session row and a Set-Cookie it never asked for. A deliberate
     * write that should create a session (a login) simply does not ask.
     */
    public function exists(): bool;

    /**
     * The current session id, or '' when there is no session.
     */
    public function getId(): string;

    /**
     * Move the session's contents to a fresh id, to defeat session fixation at
     * a privilege transition.
     *
     * @param bool $deleteOld Whether the previous id should stop resolving.
     *                        Implementations differ in how immediately they
     *                        honour that; see the implementation docs.
     */
    public function regenerate(bool $deleteOld = true): void;

    /**
     * Discard this session's contents and continue under a fresh id. Used at
     * logout, so the pre-logout id is neither replayable nor inheritable.
     */
    public function destroy(): void;
}
