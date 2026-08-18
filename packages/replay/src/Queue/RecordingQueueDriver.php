<?php

declare(strict_types=1);

namespace Quiote\Replay\Queue;

use Quiote\Queue\JobPayload;
use Quiote\Queue\QueueDriverInterface;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * A decorating queue driver: wraps a real inner {@see QueueDriverInterface}
 * and appends one {@see EffectKind::Queue} entry per {@see push()} to an
 * injected {@see EffectLedger}, then returns exactly as the real driver did.
 *
 * Scoped to `push()` only, per the record/replay plan's own framing --
 * `reserve()`/`ack()`/`release()`/`discard()` on
 * {@see \Quiote\Queue\PollableQueueDriverInterface} belong to an
 * out-of-process worker polling the backlog later, not to the request that
 * enqueued the job, and are not observed here.
 *
 * `push()` returns `void`, and no driver hands back an id or other value a
 * caller could observe from the call itself (see e.g.
 * `Quiote\Queue\Db\DbQueueDriver`/`Quiote\Queue\Redis\RedisQueueDriver`,
 * whose generated ids are internal to the backend) -- so the effect's
 * `result` is `null`; there is nothing else genuine to record.
 *
 * A real driver exception is never swallowed: no effect is recorded for a
 * failed push, and the exception propagates exactly as it would through the
 * undecorated driver, matching every other recorder in this package.
 */
final class RecordingQueueDriver implements QueueDriverInterface
{
    public function __construct(
        private readonly QueueDriverInterface $driver,
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    #[\Override]
    public function push(JobPayload $payload): void
    {
        $start = $this->clock->monotonic();
        $this->driver->push($payload);
        $duration = $this->durationMicros($start);

        $this->ledger->record(
            EffectKind::Queue,
            QueueFingerprint::ofPush($payload),
            self::describe($payload),
            null,
            $duration,
        );
    }

    /** @return array<string, mixed> */
    public static function describe(JobPayload $payload): array
    {
        return [
            'op' => 'push',
            'jobClass' => $payload->jobClass,
            'params' => $payload->params,
            'attempts' => $payload->attempts,
            'availableAt' => $payload->availableAt?->format(\DATE_ATOM),
        ];
    }

    /** @return non-negative-int */
    private function durationMicros(float $startMonotonicSeconds): int
    {
        return max(0, (int) round(($this->clock->monotonic() - $startMonotonicSeconds) * 1_000_000));
    }
}
