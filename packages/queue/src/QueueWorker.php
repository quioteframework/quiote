<?php

namespace Quiote\Queue;

/** Drives a {@see PollableQueueDriverInterface}'s backlog one job at a time; used by `queue:work`. */
final readonly class QueueWorker
{
    public function __construct(private JobExecutor $executor)
    {
    }

    /** @return bool True if a job was reserved and processed (success or failure); false if the queue was empty. */
    public function processNext(PollableQueueDriverInterface $driver): bool
    {
        $reserved = $driver->reserve();
        if ($reserved === null) {
            return false;
        }

        $failure = $this->executor->attempt($reserved->payload);
        if ($failure === null) {
            $driver->ack($reserved);
        } elseif ($failure->shouldRetry) {
            $driver->release($reserved, $failure->backoffSeconds);
        } else {
            $driver->discard($reserved);
        }

        return true;
    }
}
