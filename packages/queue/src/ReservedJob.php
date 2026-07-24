<?php

namespace Quiote\Queue;

/**
 * A {@see JobPayload} claimed off a {@see PollableQueueDriverInterface} by
 * `reserve()`. `$id` is driver-specific (e.g. a row id) and is only
 * meaningful back to the same driver via `ack()`/`release()`/`discard()`.
 */
final readonly class ReservedJob
{
    public function __construct(
        public string $id,
        public JobPayload $payload,
    ) {
    }
}
