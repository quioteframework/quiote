<?php

namespace Quiote\Queue;

/** Outcome of a failed {@see JobExecutor::attempt()} call: retry, or give up. */
final readonly class ExecutionFailure
{
    private function __construct(
        public \Throwable $exception,
        public int $attempts,
        public bool $shouldRetry,
        public int $backoffSeconds,
    ) {
    }

    public static function retry(\Throwable $exception, int $attempts, int $backoffSeconds): self
    {
        return new self($exception, $attempts, true, $backoffSeconds);
    }

    /** Retries exhausted; the caller has already recorded this to a {@see FailedJobStoreInterface}. */
    public static function exhausted(\Throwable $exception, int $attempts): self
    {
        return new self($exception, $attempts, false, 0);
    }
}
