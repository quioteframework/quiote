<?php

namespace Quiote\Queue;

/**
 * Opt-in per-job retry policy. A {@see Job} that does not implement this
 * gets the config-level defaults instead (`queue.retry.max_attempts`,
 * `queue.retry.backoff_seconds`) — see {@see JobExecutor}.
 */
interface RetryableJob extends Job
{
    /** Total attempts allowed, including the first. */
    public function maxAttempts(): int;

    /** Delay before the given (1-based) retry attempt. */
    public function backoffSeconds(int $attempt): int;
}
