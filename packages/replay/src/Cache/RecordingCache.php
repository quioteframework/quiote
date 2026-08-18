<?php

declare(strict_types=1);

namespace Quiote\Replay\Cache;

use Quiote\Cache\CacheInterface;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * A decorating PSR-16 cache: wraps a real inner `CacheInterface` and appends
 * one {@see EffectKind::Cache} entry per call to an injected
 * {@see EffectLedger}, returning the real result completely untouched to the
 * caller.
 *
 * Fingerprint is `"{op}:{key}"` (e.g. `"get:orders.42"`, `"set:orders.42"`),
 * not the bare key -- a bare-key fingerprint would let
 * {@see \Quiote\Replay\Replay\LedgerMatcher}'s sequence fallback hand a
 * `get()` call the recorded result of a `set()`/`has()` on the same key if
 * replay ever asked for that key's operations in a different order than
 * recording did (a real, if narrow, risk once cache traffic for one key
 * interleaves several operation kinds). Scoping the fingerprint by operation
 * keeps each operation's own recorded sequence independent, matching how the
 * HTTP recorder scopes its fingerprint by method for the same reason.
 *
 * `get()`'s hit/miss distinction is recorded explicitly (`['hit' => bool,
 * 'value' => mixed]`), not inferred from comparing the returned value to the
 * caller's `$default` -- PSR-16 cannot otherwise tell a stored `null` apart
 * from a miss, and collapsing that distinction would let replay silently
 * turn a real stored `null` into a miss or vice versa. The caller's own
 * `$default` at replay time is honored for a recorded miss, not whatever
 * default happened to be passed when recording -- callers are free to pass a
 * different default across runs, and only the backend's actual hit/miss
 * state is this decorator's business to reproduce.
 *
 * `getMultiple()`/`setMultiple()`/`deleteMultiple()` are implemented as
 * repeated calls to this class's own `get()`/`set()`/`delete()` rather than
 * delegating to the inner cache's native multi-key methods: this reuses the
 * single-key recording/hit-miss logic exactly instead of duplicating it
 * under a second fingerprint scheme, at the cost of the inner backend's own
 * multi-key round-trip optimization -- an acceptable trade for a recording
 * decorator, whose job is observing traffic, not minimizing it.
 *
 * A real-cache exception is never swallowed: no effect is recorded for a
 * failed call, and the exception propagates exactly as it would through the
 * undecorated cache, matching the same rule `Quiote\Replay\Db\RecordingPdo`
 * and `Quiote\Replay\Http\RecordingHttpTransport` already follow.
 */
final class RecordingCache implements CacheInterface
{
    public function __construct(
        private readonly \Psr\SimpleCache\CacheInterface $cache,
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        // A sentinel distinct from any value a real backend could return, so
        // a stored `null` is told apart from a genuine miss without a second
        // round-trip to the backend.
        $sentinel = new \stdClass();

        $start = $this->clock->monotonic();
        $raw = $this->cache->get($key, $sentinel);
        $duration = $this->durationMicros($start);

        $hit = $raw !== $sentinel;
        $this->ledger->record(
            EffectKind::Cache,
            CacheFingerprint::of('get', $key),
            ['op' => 'get', 'key' => $key],
            $hit ? ['hit' => true, 'value' => $raw] : ['hit' => false],
            $duration,
        );

        return $hit ? $raw : $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $start = $this->clock->monotonic();
        $result = $this->cache->set($key, $value, $ttl);

        $this->ledger->record(
            EffectKind::Cache,
            CacheFingerprint::of('set', $key),
            ['op' => 'set', 'key' => $key, 'value' => $value, 'ttl' => $this->describeTtl($ttl)],
            $result,
            $this->durationMicros($start),
        );

        return $result;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        $start = $this->clock->monotonic();
        $result = $this->cache->delete($key);

        $this->ledger->record(
            EffectKind::Cache,
            CacheFingerprint::of('delete', $key),
            ['op' => 'delete', 'key' => $key],
            $result,
            $this->durationMicros($start),
        );

        return $result;
    }

    #[\Override]
    public function clear(): bool
    {
        $start = $this->clock->monotonic();
        $result = $this->cache->clear();

        $this->ledger->record(
            EffectKind::Cache,
            CacheFingerprint::CLEAR,
            ['op' => 'clear'],
            $result,
            $this->durationMicros($start),
        );

        return $result;
    }

    #[\Override]
    public function has(string $key): bool
    {
        $start = $this->clock->monotonic();
        $result = $this->cache->has($key);

        $this->ledger->record(
            EffectKind::Cache,
            CacheFingerprint::of('has', $key),
            ['op' => 'has', 'key' => $key],
            $result,
            $this->durationMicros($start),
        );

        return $result;
    }

    /** @param iterable<mixed, array-key> $keys */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    /** @param iterable<array-key, mixed> $values */
    #[\Override]
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $allSucceeded = true;
        foreach ($values as $key => $value) {
            $allSucceeded = $this->set((string) $key, $value, $ttl) && $allSucceeded;
        }

        return $allSucceeded;
    }

    /** @param iterable<mixed, array-key> $keys */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $allSucceeded = true;
        foreach ($keys as $key) {
            $allSucceeded = $this->delete((string) $key) && $allSucceeded;
        }

        return $allSucceeded;
    }

    private function describeTtl(null|int|\DateInterval $ttl): null|int|string
    {
        return $ttl instanceof \DateInterval ? $ttl->format('%y-%m-%dT%h:%i:%s') : $ttl;
    }

    /** @return non-negative-int */
    private function durationMicros(float $startMonotonicSeconds): int
    {
        return max(0, (int) round(($this->clock->monotonic() - $startMonotonicSeconds) * 1_000_000));
    }
}
