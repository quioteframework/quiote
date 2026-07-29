<?php

// No namespace: loaded via composer classmap for test/lib

use Quiote\Session\SessionBagInterface;

/**
 * A SessionBagInterface holding everything in memory. The replacement for the
 * old MockStorage: tests that only need somewhere for the User hierarchy to
 * read and write want this, not a real session backend.
 *
 * exists() is settable, because it is the switch several write-on-change
 * behaviours key off -- a logout or a token-derived marker must not create a
 * session that was not there.
 */
class InMemorySessionBag implements SessionBagInterface
{
    /** @var array<string, mixed> */
    public array $data = [];
    public int $writes = 0;
    public string $id = 'in-memory-session-id';

    public function __construct(private bool $exists = true)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->writes++;
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function setExists(bool $exists): void
    {
        $this->exists = $exists;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function regenerate(bool $deleteOld = true): void
    {
        $this->id = 'regenerated-' . $this->id;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->id = 'destroyed-' . $this->id;
    }
}
