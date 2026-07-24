<?php

namespace Quiote\Queue;

/**
 * A queue driver with a persistent backlog that an out-of-process worker
 * (`queue:work`, see {@see QueueWorker}) can poll. Implemented by
 * `quioteframework/queue-db`'s `DbQueueDriver`, deliberately not by
 * {@see SyncQueueDriver} (nothing to poll).
 */
interface PollableQueueDriverInterface extends QueueDriverInterface
{
    /** Claim and return the next due job, or null if the queue is empty. */
    public function reserve(): ?ReservedJob;

    /** Mark a reserved job as successfully processed; removes it permanently. */
    public function ack(ReservedJob $job): void;

    /** Return a reserved job to the backlog, available again after $delaySeconds. */
    public function release(ReservedJob $job, int $delaySeconds): void;

    /**
     * Remove a reserved job permanently after retries are exhausted. Dead-letter
     * recording itself already happened via {@see FailedJobStoreInterface} inside
     * {@see JobExecutor} — this only stops the driver from serving it again.
     */
    public function discard(ReservedJob $job): void;
}
