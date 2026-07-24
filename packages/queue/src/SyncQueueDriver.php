<?php

namespace Quiote\Queue;

/**
 * The always-available default driver (`queue.default_driver = sync`):
 * `push()` executes the job inline, in-process, with blocking retries via
 * {@see JobExecutor::executeWithRetries()}. Safe for dev/test; production
 * use should configure a persistent driver (e.g. `quioteframework/queue-db`)
 * so job execution doesn't block the request that pushed it.
 */
final readonly class SyncQueueDriver implements QueueDriverInterface
{
    public function __construct(private JobExecutor $executor)
    {
    }

    public function push(JobPayload $payload): void
    {
        $this->executor->executeWithRetries($payload);
    }
}
