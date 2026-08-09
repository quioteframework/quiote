<?php

declare(strict_types=1);

namespace Quiote\Session;

/**
 * Mutable session handle. Deliberately an object rather than a plain array: PSR-7
 * requests are immutable, so a request attribute holding an array would be
 * invisible to code higher up the middleware stack once a downstream handler
 * mutates its own (forked) copy. Because this is an object, the same instance is
 * shared across every withAttribute()-forked request in the pipeline — mutations
 * made deep in a handler are visible to SessionMiddleware once control returns.
 */
final class Session
{
    /**
     * @param array<string, mixed> $data
     * @param bool $new Whether this session was freshly generated for this
     *                  request rather than loaded from persistence. Tracked
     *                  separately from $dirty so SessionManager can tell "an
     *                  untouched brand-new session, nothing to persist or
     *                  cookie yet" apart from "an existing session with
     *                  nothing changed this request" -- the latter still
     *                  needs its cookie refreshed (sliding expiration) even
     *                  though there's nothing new to write to storage.
     */
    public function __construct(
        private string $sid,
        private array $data,
        private bool $dirty,
        private bool $new = false,
    ) {
    }

    /**
     * Whether this session was generated for the current request rather than
     * loaded from persistence.
     *
     * Stays true for the life of the request even after writes; combine with
     * {@see isDirty()} to tell an untouched brand-new session apart from one
     * that has acquired state and needs persisting.
     */
    public function isNew(): bool
    {
        return $this->new;
    }

    /** Returns the session id, which {@see replaceId()} changes on regeneration. */
    public function getId(): string
    {
        return $this->sid;
    }

    /** Returns the value stored under the key, or $default when it is absent. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Whether the key is present, including when its stored value is null. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Stores a value under the key and marks the session dirty.
     *
     * The dirty flag is what makes {@see SessionManager::persistAndBakeCookies()}
     * write the session out at the end of the request, so a write is always
     * unconditional here — no value comparison is made against what was there.
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    /**
     * Drops the key from the session and marks it dirty.
     *
     * The session is marked dirty whether or not the key was present, so a
     * removal of an absent key still triggers a write at the end of the request.
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /** Whether the session has unwritten changes, i.e. a write happened since it was last marked clean. */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Internal hooks used by SessionManager; not intended for application code.
     */
    public function replaceId(string $sid): void
    {
        $this->sid = $sid;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function replaceData(array $data): void
    {
        $this->data = $data;
    }

    /** Forces the dirty flag on, so the session is written out even without a {@see set()} or {@see remove()}. */
    public function markDirty(): void
    {
        $this->dirty = true;
    }

    /** Clears the dirty flag, which {@see SessionManager} does once the session has been persisted. */
    public function markClean(): void
    {
        $this->dirty = false;
    }
}
