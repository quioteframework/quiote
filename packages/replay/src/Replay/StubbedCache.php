<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Cache\CacheInterface;
use Quiote\Replay\Cache\CacheFingerprint;
use Quiote\Replay\Cassette\EffectKind;

/**
 * The isolated-replay counterpart to
 * {@see \Quiote\Replay\Cache\RecordingCache}: never touches a real cache
 * backend, answering every call from an injected {@see EffectLedger} matched
 * on the same {@see CacheFingerprint} scheme the recorder used.
 *
 * Each recorded `get()` call already carries its own hit/miss state, so
 * replaying it needs no simulation of intervening writes -- the ledger
 * captured exactly what the backend held at the moment of that specific call
 * in the original request. A read (`get()`, `has()`) with no matching
 * recorded effect throws rather than fabricating a value or silently
 * returning the caller's `$default`: inventing read data would fabricate a
 * passing test, the same rule `StubbedPdo`/`StubbedHttpTransport` follow.
 *
 * A write (`set()`, `delete()`, `clear()`) is different in kind: its return
 * value is a bare success flag with no data a caller could act on
 * incorrectly, and isolated replay has no real backend for the write to
 * fail against. When a matching recorded write effect exists, its recorded
 * boolean is reproduced (so a request that observed `set()` returning
 * `false` -- e.g. a full backend -- still sees that on replay); when none
 * exists, the write silently succeeds (`true`) rather than throwing, since
 * an isolated-replay write inherently cannot affect anything and refusing
 * it would make replay brittle for code paths that write to the cache
 * without the original recording having captured every such write.
 */
final class StubbedCache implements CacheInterface
{
    public function __construct(private readonly EffectLedger $ledger)
    {
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $effect = $this->ledger->match(EffectKind::Cache, CacheFingerprint::of('get', $key));
        if ($effect === null) {
            throw new \RuntimeException(sprintf('StubbedCache: no recorded cache effect for get("%s").', $key));
        }

        $result = $effect->result;
        if (!is_array($result) || !isset($result['hit'])) {
            throw new \RuntimeException(sprintf('StubbedCache: recorded effect for get("%s") is not a valid cache read.', $key));
        }

        return $result['hit'] === true ? ($result['value'] ?? null) : $default;
    }

    #[\Override]
    public function has(string $key): bool
    {
        $effect = $this->ledger->match(EffectKind::Cache, CacheFingerprint::of('has', $key));
        if ($effect === null) {
            throw new \RuntimeException(sprintf('StubbedCache: no recorded cache effect for has("%s").', $key));
        }
        if (!is_bool($effect->result)) {
            throw new \RuntimeException(sprintf('StubbedCache: recorded effect for has("%s") is not a valid boolean.', $key));
        }

        return $effect->result;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->writeResult(CacheFingerprint::of('set', $key));
    }

    #[\Override]
    public function delete(string $key): bool
    {
        return $this->writeResult(CacheFingerprint::of('delete', $key));
    }

    #[\Override]
    public function clear(): bool
    {
        return $this->writeResult(CacheFingerprint::CLEAR);
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

    /** A write's recorded boolean when one was captured, or true when isolated replay simply has nothing to consult. */
    private function writeResult(string $fingerprint): bool
    {
        $effect = $this->ledger->match(EffectKind::Cache, $fingerprint);
        if ($effect === null) {
            return true;
        }

        return $effect->result === true;
    }
}
