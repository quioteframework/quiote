<?php

namespace Quiote\Queue;

/**
 * Minimal contract every queue driver implements. The in-process
 * {@see SyncQueueDriver} implements only this — there is nothing to poll,
 * `push()` runs the job inline. Persistent drivers additionally implement
 * {@see PollableQueueDriverInterface} so `queue:work` can drive them.
 */
interface QueueDriverInterface
{
    public function push(JobPayload $payload): void;
}
