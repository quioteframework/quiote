<?php

declare(strict_types=1);

namespace Quiote\Session;

/**
 * A {@see SessionBagInterface} that stores nothing.
 *
 * What an application gets when it configures no `session` factory slot. The
 * User hierarchy, CSRF token storage and OIDC state all read and write through
 * the bag unconditionally, so they need something to talk to even where a
 * session makes no sense -- a console command, a queue worker, a stateless
 * JSON API, a test context. This is that something, and it keeps "a User but
 * no sessions" expressible without a session backend, the way the old
 * NullStorage did for the storage slot it replaces.
 *
 * Writes are discarded rather than rejected: a component that opportunistically
 * records something in the session should not have to know whether one exists.
 * exists() answers false, so callers persisting default or empty state skip
 * their write entirely instead of relying on that.
 *
 * @since      3.0.0
 */
final class NullSessionBag implements SessionBagInterface
{
    /** Always returns $default; nothing is ever stored. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    /** Always false; no key is ever present. */
    public function has(string $key): bool
    {
        return false;
    }

    /** Discards the value silently, so opportunistic writes need no session check. */
    public function set(string $key, mixed $value): void
    {
    }

    /** No-op; there is nothing stored to remove. */
    public function remove(string $key): void
    {
    }

    /** Always false, so callers persisting default or empty state skip their write entirely. */
    public function exists(): bool
    {
        return false;
    }

    /** Always the empty string, the contract's "no session" id. */
    public function getId(): string
    {
        return '';
    }

    /** No-op; with no id and no contents there is nothing to rotate. */
    public function regenerate(bool $deleteOld = true, bool $privilegeTransition = false): void
    {
    }

    /** No-op; there is no session state to discard. */
    public function destroy(): void
    {
    }
}
