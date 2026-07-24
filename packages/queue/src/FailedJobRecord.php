<?php

namespace Quiote\Queue;

/**
 * A stored dead-letter row, as returned by {@see InspectableFailedJobStoreInterface}.
 * `$jobClass` is plain `string`, not `class-string<Job>`, for the same reason
 * as {@see JobPayload::$jobClass}: it comes from stored data that hasn't
 * been validated yet — a caller re-pushing it (`queue:failed:retry`) narrows
 * it at that point instead.
 */
final readonly class FailedJobRecord
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $id,
        public string $jobClass,
        public array $params,
        public string $exceptionClass,
        public string $exceptionMessage,
        public string $exceptionTrace,
        public int $attempts,
        public \DateTimeImmutable $failedAt,
    ) {
    }
}
