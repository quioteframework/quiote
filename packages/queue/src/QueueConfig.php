<?php

namespace Quiote\Queue;

use Quiote\Config\Config;

/**
 * Typed snapshot of the `queue.*` settings family.
 * Defaults here are read as fallbacks only — {@see QueuePlugin} is what
 * actually publishes them into {@see Config} via `configDefault()`.
 */
final readonly class QueueConfig
{
    public function __construct(
        public string $defaultDriver,
        public int $retryMaxAttempts,
        public int $retryBackoffSeconds,
    ) {
    }

    /**
     * Reads the `queue.*` family out of {@see Config} into one immutable
     * snapshot, falling back to the `sync` driver with three attempts five
     * seconds apart when the app (or {@see QueuePlugin}) has published nothing.
     */
    public static function fromConfig(): self
    {
        return new self(
            defaultDriver: Config::getString('queue.default_driver', 'sync'),
            retryMaxAttempts: (int) Config::getInt('queue.retry.max_attempts', 3),
            retryBackoffSeconds: (int) Config::getInt('queue.retry.backoff_seconds', 5),
        );
    }
}
