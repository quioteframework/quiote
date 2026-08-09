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
    /**
     * Hands a job off to the driver's backend.
     *
     * Implementors either enqueue the payload for later execution or, for the
     * in-process {@see SyncQueueDriver}, run it inline and block until it
     * succeeds or exhausts its retries. A payload carrying a non-null
     * {@see JobPayload::$availableAt} must not become visible to a worker
     * before that moment.
     */
    public function push(JobPayload $payload): void;
}
