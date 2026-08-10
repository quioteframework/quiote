<?php

declare(strict_types=1);

// No namespace: loaded via composer classmap for test/lib, matching InMemorySessionBag.

use Quiote\Session\SessionBagInterface;

/**
 * A session bag whose every write fails, standing in for a session backend
 * outage (Redis down, a full disk, a PDO connection lost mid-request).
 */
final class FailingSessionBag implements SessionBagInterface
{
    /** @var list<string> Methods that were attempted, so "it kept going" is assertable. */
    public array $attempted = [];

    public function __construct(private readonly bool $failReads = false)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->attempted[] = 'get:' . $key;
        if ($this->failReads) {
            throw new RuntimeException('session backend unreachable');
        }

        return $default;
    }

    public function has(string $key): bool
    {
        $this->attempted[] = 'has:' . $key;

        return false;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attempted[] = 'set:' . $key;
        throw new RuntimeException('session backend unreachable');
    }

    public function remove(string $key): void
    {
        $this->attempted[] = 'remove:' . $key;
        throw new RuntimeException('session backend unreachable');
    }

    public function exists(): bool
    {
        return true;
    }

    public function getId(): string
    {
        return 'failing-sid';
    }

    public function regenerate(bool $deleteOld = true, bool $privilegeTransition = false): void
    {
        $this->attempted[] = 'regenerate';
        throw new RuntimeException('session backend unreachable');
    }

    public function destroy(): void
    {
        $this->attempted[] = 'destroy';
        throw new RuntimeException('session backend unreachable');
    }
}
