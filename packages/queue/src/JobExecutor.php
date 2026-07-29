<?php

namespace Quiote\Queue;

use Quiote\DI\Container;

/**
 * Shared retry/backoff decision logic used by both {@see SyncQueueDriver}
 * (in-process blocking retry loop) and {@see QueueWorker} (one attempt per
 * poll, deferred retry via the driver), so the policy is not duplicated per
 * driver. Jobs are rebuilt per attempt via {@see Container::make()} — the
 * same fresh-per-call autowiring actions/views already get.
 */
final readonly class JobExecutor
{
    public function __construct(
        private Container $container,
        private FailedJobStoreInterface $failedJobStore,
        private int $defaultMaxAttempts = 3,
        private int $defaultBackoffSeconds = 5,
    ) {
    }

    /**
     * Construct and run the job once. Returns null on success, or an
     * {@see ExecutionFailure} describing whether to retry (with delay) or
     * give up (already recorded to the failed-job store in that case).
     */
    public function attempt(JobPayload $payload): ?ExecutionFailure
    {
        // Checked BEFORE constructing, not after: $payload->jobClass comes from
        // stored data (hence its plain `string` type, see JobPayload), so a queue
        // row an attacker can influence would otherwise have any autoloadable
        // class in the app or vendor tree instantiated with attacker-chosen
        // constructor arguments -- object injection via a constructor side effect
        // -- before this check ever ran. is_a() with $allow_string resolves the
        // hierarchy without instantiating anything.
        if (!is_a($payload->jobClass, Job::class, true)) {
            throw new \RuntimeException(sprintf(
                'Queue job class "%s" must implement %s.',
                $payload->jobClass,
                Job::class,
            ));
        }

        $job = $this->container->make($payload->jobClass, $payload->params);
        if (!$job instanceof Job) {
            // The container is free to answer with a decorator/substitute, so the
            // static check above does not make this redundant.
            throw new \RuntimeException(sprintf(
                'Queue job class "%s" must implement %s, got %s.',
                $payload->jobClass,
                Job::class,
                get_debug_type($job),
            ));
        }

        try {
            $job->handle();
            return null;
        } catch (\Throwable $e) {
            $attempts = $payload->attempts + 1;
            $maxAttempts = $job instanceof RetryableJob ? $job->maxAttempts() : $this->defaultMaxAttempts;

            if ($attempts < $maxAttempts) {
                $backoffSeconds = $job instanceof RetryableJob ? $job->backoffSeconds($attempts) : $this->defaultBackoffSeconds;
                return ExecutionFailure::retry($e, $attempts, $backoffSeconds);
            }

            $this->failedJobStore->record(new FailedJob(
                jobClass: $job::class,
                params: $payload->params,
                exceptionClass: $e::class,
                exceptionMessage: $e->getMessage(),
                exceptionTrace: $e->getTraceAsString(),
                attempts: $attempts,
            ));
            return ExecutionFailure::exhausted($e, $attempts);
        }
    }

    /**
     * Run a job to completion in-process, blocking (with `usleep`) between
     * retries. Used by {@see SyncQueueDriver} only — a persistent driver's
     * worker loop instead defers retries via `release()` (see {@see QueueWorker}).
     */
    public function executeWithRetries(JobPayload $payload): void
    {
        $current = $payload;
        while (true) {
            $failure = $this->attempt($current);
            if ($failure === null || !$failure->shouldRetry) {
                return;
            }
            if ($failure->backoffSeconds > 0) {
                usleep($failure->backoffSeconds * 1_000_000);
            }
            $current = $current->withAttempts($failure->attempts);
        }
    }
}
