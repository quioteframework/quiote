<?php

// No namespace: loaded via composer classmap for test/lib

use Quiote\Context;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Mock storage avoiding native session interactions (startup/shutdown no-ops).
 * Provides minimal API subset used by context reset logic.
 */
class MockStorage implements ResetInterface
{
    private bool $initialized = false;
    private bool $started = false;
    private bool $shutdown = false;
    /** @var array<string, mixed> */
    public array $parameters = [];
    /**
     * Simple in-memory storage map keyed by namespace.
     * @var array<string, mixed>
     */
    private array $data = [];

    /** @param array<string, mixed> $parameters */
    public function initialize(Context $ctx, array $parameters = []): void
    {
        $this->initialized = true;
        $this->parameters = $parameters;
    }
    public function startup(): void
    {
        $this->started = true; // do NOT call session_start()
    }
    public function shutdown(): void
    {
        $this->shutdown = true; // do NOT call session_write_close()
    }
    public function reset(): void
    {
        $this->started = false;
        $this->shutdown = false;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }
    public function isStarted(): bool
    {
        return $this->started;
    }
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    // Minimal API used by User / SecurityUser
    public function retrieve(string $ns): mixed
    {
        return $this->data[$ns] ?? null;
    }
    public function store(string $ns, mixed $value): void
    {
        $this->data[$ns] = $value;
    }
    public function has(string $ns): bool
    {
        return array_key_exists($ns, $this->data);
    }
}
