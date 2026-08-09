<?php

namespace Quiote\Queue;

/**
 * A unit of background work. Instantiated fresh per attempt via
 * {@see \Quiote\DI\Container::make()} — the same fresh-per-call autowiring
 * actions/views already get — so constructor-injected services autowire
 * normally and only the job's own arguments need to travel through the
 * queue as {@see JobPayload::$params}.
 */
interface Job
{
    /**
     * Performs the job's work.
     *
     * Called once per attempt on a freshly built instance. Throwing signals
     * failure: {@see JobExecutor} retries the job up to
     * `queue.retry.max_attempts` and then records it with the configured
     * {@see FailedJobStoreInterface}. Returning normally marks the attempt
     * successful.
     */
    public function handle(): void;
}
