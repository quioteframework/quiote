<?php

namespace Quiote\Queue;

/** A job whose retries were exhausted, handed to a {@see FailedJobStoreInterface}. */
final readonly class FailedJob
{
    /**
     * @param class-string<Job> $jobClass
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $jobClass,
        public array $params,
        public string $exceptionClass,
        public string $exceptionMessage,
        public string $exceptionTrace,
        public int $attempts,
    ) {
    }
}
