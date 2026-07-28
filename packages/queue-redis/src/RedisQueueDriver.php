<?php

declare(strict_types=1);

namespace Quiote\Queue\Redis;

use Predis\ClientInterface;
use Quiote\Queue\JobPayload;
use Quiote\Queue\PollableQueueDriverInterface;
use Quiote\Queue\ReservedJob;
use RuntimeException;

/**
 * Redis-backed {@see PollableQueueDriverInterface}. Ready jobs live in a Redis
 * LIST (`{prefix}:ready`); `reserve()` atomically moves one into a
 * `{prefix}:processing` LIST via `RPOPLPUSH` (the classic reliable-queue
 * pattern) so a crashed worker's in-flight jobs are still recoverable from
 * that list rather than lost. Delayed/released jobs live in a ZSET
 * (`{prefix}:delayed`) scored by their `available_at` unix timestamp;
 * `reserve()` first promotes any due members back onto the ready list.
 *
 * `ReservedJob::$id` is the exact JSON-encoded list entry (each entry embeds
 * a random `uid` so two otherwise-identical jobs remain distinct strings) —
 * driver-specific per {@see ReservedJob}'s contract, used as the `LREM`
 * target in `ack()`/`release()`/`discard()`.
 */
final readonly class RedisQueueDriver implements PollableQueueDriverInterface
{
    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'quiote_queue',
    ) {
    }

    public function push(JobPayload $payload): void
    {
        $availableAt = $payload->availableAt?->getTimestamp() ?? time();
        $entry = $this->encode($payload, $availableAt);

        if ($availableAt <= time()) {
            $this->redis->lpush($this->readyKey(), [$entry]);
        } else {
            $this->redis->zadd($this->delayedKey(), [$entry => $availableAt]);
        }
    }

    public function reserve(): ?ReservedJob
    {
        $this->promoteDueDelayed();

        $entry = $this->redis->rpoplpush($this->readyKey(), $this->processingKey());
        if (!is_string($entry) || $entry === '') {
            return null;
        }

        return $this->toReservedJob($entry);
    }

    public function ack(ReservedJob $job): void
    {
        $this->redis->lrem($this->processingKey(), 0, $job->id);
    }

    public function release(ReservedJob $job, int $delaySeconds): void
    {
        $this->redis->lrem($this->processingKey(), 0, $job->id);

        $availableAt = time() + max(0, $delaySeconds);
        $entry = $this->encode($job->payload->withAttempts($job->payload->attempts + 1), $availableAt, $job->id);

        if ($availableAt <= time()) {
            $this->redis->lpush($this->readyKey(), [$entry]);
        } else {
            $this->redis->zadd($this->delayedKey(), [$entry => $availableAt]);
        }
    }

    public function discard(ReservedJob $job): void
    {
        $this->redis->lrem($this->processingKey(), 0, $job->id);
    }

    private function promoteDueDelayed(): void
    {
        $now = time();
        /** @var list<string> $due */
        $due = $this->redis->zrangebyscore($this->delayedKey(), '-inf', (string) $now);
        foreach ($due as $entry) {
            $this->redis->zrem($this->delayedKey(), $entry);
            $this->redis->lpush($this->readyKey(), [$entry]);
        }
    }

    private function encode(JobPayload $payload, int $availableAt, ?string $reuseUid = null): string
    {
        $uid = $reuseUid !== null ? $this->extractUid($reuseUid) : bin2hex(random_bytes(16));

        return json_encode([
            'uid' => $uid,
            'job_class' => $payload->jobClass,
            'params' => $payload->params,
            'attempts' => $payload->attempts,
            'available_at' => $availableAt,
        ], JSON_THROW_ON_ERROR);
    }

    private function extractUid(string $entry): string
    {
        $decoded = json_decode($entry, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['uid']) || !is_string($decoded['uid'])) {
            return bin2hex(random_bytes(16));
        }

        return $decoded['uid'];
    }

    private function toReservedJob(string $entry): ReservedJob
    {
        $decoded = json_decode($entry, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Queue entry on "%s" is not a JSON object: %s', $this->readyKey(), $entry));
        }

        $jobClass = $decoded['job_class'] ?? null;
        if (!is_string($jobClass)) {
            throw new RuntimeException(sprintf('Queue entry on "%s" has a non-string "job_class".', $this->readyKey()));
        }

        $params = $decoded['params'] ?? [];
        if (!is_array($params)) {
            throw new RuntimeException(sprintf('Queue entry on "%s" has non-array "params".', $this->readyKey()));
        }
        $stringKeyedParams = [];
        foreach ($params as $key => $value) {
            $stringKeyedParams[(string) $key] = $value;
        }

        $attempts = $decoded['attempts'] ?? 0;
        if (!is_int($attempts)) {
            throw new RuntimeException(sprintf('Queue entry on "%s" has a non-int "attempts".', $this->readyKey()));
        }

        return new ReservedJob($entry, new JobPayload($jobClass, $stringKeyedParams, $attempts));
    }

    private function readyKey(): string
    {
        return $this->prefix . ':ready';
    }

    private function processingKey(): string
    {
        return $this->prefix . ':processing';
    }

    private function delayedKey(): string
    {
        return $this->prefix . ':delayed';
    }
}
