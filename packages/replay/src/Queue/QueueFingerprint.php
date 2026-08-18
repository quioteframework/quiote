<?php

declare(strict_types=1);

namespace Quiote\Replay\Queue;

use Quiote\Queue\JobPayload;

/**
 * The fingerprint scheme shared by {@see RecordingQueueDriver} and
 * {@see \Quiote\Replay\Replay\AssertingQueueDriver}: `"push:{jobClass}:{json
 * of params}"`. Prefixed by operation (mirroring
 * {@see \Quiote\Replay\Cache\CacheFingerprint} and
 * {@see \Quiote\Replay\Http\HttpFingerprint}) so a future queue operation
 * recorded under the same {@see \Quiote\Replay\Cassette\EffectKind::Queue}
 * (e.g. a poll-side `reserve()`) cannot collide with a `push()` fingerprint.
 *
 * Two pushes of the identically-shaped job (same class and params) fingerprint
 * identically on purpose -- {@see \Quiote\Replay\Replay\LedgerMatcher}'s
 * sequence fallback is what keeps repeated identical pushes distinguishable
 * and ordered, exactly as it already does for two identical queries or cache
 * reads.
 */
final class QueueFingerprint
{
    public static function ofPush(JobPayload $payload): string
    {
        return 'push:' . $payload->jobClass . ':' . json_encode($payload->params, JSON_THROW_ON_ERROR);
    }
}
