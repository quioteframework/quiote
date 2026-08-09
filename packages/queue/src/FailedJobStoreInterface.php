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
    /**
     * Takes delivery of a job that has permanently failed.
     *
     * Called by {@see JobExecutor} once retries are exhausted, before the
     * driver is told to discard the job. Implementors decide whether the
     * record is kept (a queryable store) or merely reported and dropped
     * ({@see LogFailedJobStore}).
     */
    public function record(FailedJob $failedJob): void;
}
