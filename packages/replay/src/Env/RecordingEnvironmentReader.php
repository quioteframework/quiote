<?php

declare(strict_types=1);

namespace Quiote\Replay\Env;

use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Environment\EnvironmentReaderInterface;

/**
 * A decorating environment reader: wraps a real inner
 * {@see EnvironmentReaderInterface} and appends one {@see EffectKind::Env}
 * entry per `get()` call to an injected {@see EffectLedger}, returning the
 * real value completely untouched to the caller.
 *
 * Fingerprint is the bare variable name -- unlike
 * {@see \Quiote\Replay\Cache\RecordingCache}'s operation-scoped fingerprint,
 * there is only one operation here (`get()`; environment variables are never
 * written through this interface), so there is no cross-operation collision
 * to guard against.
 *
 * `getenv()`'s own contract already distinguishes "unset" (`false`) from any
 * string value, including an empty one, so no extra hit/miss sentinel is
 * needed the way {@see \Quiote\Replay\Cache\RecordingCache::get()} needed one
 * for PSR-16's `null`-vs-miss ambiguity: the recorded `result` is simply the
 * exact `string|false` the inner reader returned.
 *
 * A real-reader exception is never swallowed: no effect is recorded for a
 * failed call, and the exception propagates exactly as it would through the
 * undecorated reader, matching every other recorder in this package.
 */
final class RecordingEnvironmentReader implements EnvironmentReaderInterface
{
    public function __construct(
        private readonly EnvironmentReaderInterface $reader,
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    #[\Override]
    public function get(string $name): string|false
    {
        $start = $this->clock->monotonic();
        $value = $this->reader->get($name);
        $duration = $this->durationMicros($start);

        $this->ledger->record(
            EffectKind::Env,
            $name,
            ['name' => $name],
            $value,
            $duration,
        );

        return $value;
    }

    /** @return non-negative-int */
    private function durationMicros(float $startMonotonicSeconds): int
    {
        return max(0, (int) round(($this->clock->monotonic() - $startMonotonicSeconds) * 1_000_000));
    }
}
