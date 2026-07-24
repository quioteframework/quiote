<?php

namespace Quiote\Queue;

/**
 * Dead-letter sink for jobs that exhausted their retries. Independent of
 * the queue driver in use — {@see QueuePlugin} binds a default
 * {@see LogFailedJobStore}; `quioteframework/queue-db` offers a persistent
 * `DbFailedJobStore` an app can bind instead, regardless of which
 * {@see QueueDriverInterface} it queues jobs through.
 */
interface FailedJobStoreInterface
{
    public function record(FailedJob $failedJob): void;
}
