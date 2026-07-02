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
     */
    public function __construct(
        private string $sid,
        private array $data,
        private bool $dirty,
    ) {
    }

    public function getId(): string
    {
        return $this->sid;
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
        $this->data[$key] = $value;
        $this->dirty = true;
    }

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

    public function markDirty(): void
    {
        $this->dirty = true;
    }

    public function markClean(): void
    {
        $this->dirty = false;
    }
}
