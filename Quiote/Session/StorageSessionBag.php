<?php

declare(strict_types=1);

namespace Quiote\Session;

use Quiote\Storage\SessionStorage;

/**
 * {@see SessionBagInterface} over the legacy `storage` factory slot.
 *
 * This is the compatibility half of the seam: it lets the User hierarchy, CSRF
 * token storage and OIDC state move onto the interface with no behavioural
 * change at all, before any application chooses a different backend. An
 * application that configures nothing gets exactly this, wrapping whatever its
 * `storage` slot already named.
 *
 * The wrapped object is typed loosely on purpose. The `storage` factory slot
 * declares no `must_implement` constraint, so an application (or a test double)
 * may supply something that does not extend {@see \Quiote\Storage\Storage};
 * every call here is therefore guarded rather than assumed.
 *
 * @since      2.1.0
 */
final class StorageSessionBag implements SessionBagInterface
{
    public function __construct(private readonly object $storage)
    {
    }

    /**
     * The wrapped storage component, for the few callers that still need the
     * concrete object during the migration.
     */
    public function getStorage(): object
    {
        return $this->storage;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!method_exists($this->storage, 'retrieve')) {
            return $default;
        }

        $value = $this->storage->retrieve($key);

        // SessionStorage answers null for a missing key; NullStorage answers
        // false. Both mean "nothing stored" -- normalize, rather than leaving
        // every caller to rediscover the difference via loose comparison.
        if ($value === null || $value === false) {
            return $default;
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, null) !== null;
    }

    public function set(string $key, mixed $value): void
    {
        if (method_exists($this->storage, 'store')) {
            $this->storage->store($key, $value);
        }
    }

    public function remove(string $key): void
    {
        if (method_exists($this->storage, 'remove')) {
            $this->storage->remove($key);
        }
    }

    public function exists(): bool
    {
        // Only SessionStorage can both answer this and create a session as a
        // side effect of a write. Anything else has no such hazard, so a write
        // is always safe.
        if ($this->storage instanceof SessionStorage) {
            return $this->storage->hasSession();
        }

        return true;
    }

    public function getId(): string
    {
        if ($this->storage instanceof SessionStorage) {
            return $this->storage->getId();
        }

        return '';
    }

    /**
     * Delegates to session_regenerate_id() via SessionStorage, which deletes
     * the old record immediately when $deleteOld is true -- a zero-length
     * window, unlike SessionManager's grace-window migration.
     */
    public function regenerate(bool $deleteOld = true): void
    {
        if (method_exists($this->storage, 'regenerate')) {
            $this->storage->regenerate($deleteOld);
        }
    }

    public function destroy(): void
    {
        // No wholesale "empty the session" on the legacy path, so the caller's
        // keys are removed by name; regenerate() then abandons the old id.
        $this->regenerate(true);
    }
}
