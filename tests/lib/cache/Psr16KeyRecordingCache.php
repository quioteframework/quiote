<?php

declare(strict_types=1);

namespace Quiote\Test\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache that records every key it is asked about and validates it the
 * way symfony/cache does, so a key using a reserved character fails the test
 * regardless of the `zend.assertions` the suite happens to run under. Symfony's
 * own PSR-16 wrapper guards its key validation with `assert()`, which means an
 * illegal key passes silently in production-configured PHP (`-1`) and throws in
 * development (`1`) — the reason a reserved character in a framework cache key
 * can survive a green test run.
 */
final class Psr16KeyRecordingCache implements CacheInterface
{
    /** Reserved by PSR-16 §1.3. */
    private const RESERVED = '{}()/\\@:';

    /** @var list<string> */
    private array $keys = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<string> */
    private array $illegal = [];

    /** @return list<string> Every key seen, in order. */
    public function recordedKeys(): array
    {
        return $this->keys;
    }

    /** @return list<string> The subset of recorded keys that no conforming PSR-16 implementation must accept. */
    public function illegalKeys(): array
    {
        return $this->illegal;
    }

    private function record(string $key): void
    {
        $this->keys[] = $key;
        if ($key === '' || strpbrk($key, self::RESERVED) !== false) {
            $this->illegal[] = $key;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->record($key);
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->record($key);
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->record($key);
        unset($this->values[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }
        return true;
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->record($key);
        return array_key_exists($key, $this->values);
    }
}
