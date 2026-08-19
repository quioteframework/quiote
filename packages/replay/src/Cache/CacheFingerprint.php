<?php

declare(strict_types=1);

namespace Quiote\Replay\Cache;

/**
 * The fingerprint scheme shared by {@see RecordingCache} and
 * {@see \Quiote\Replay\Replay\StubbedCache}: `"{op}:{key}"` for a single-key
 * operation, a fixed literal for `clear()`. Scoped by operation, not the bare
 * key, so a `get()` call can never be matched against a `set()`/`has()`
 * effect recorded for the same key -- see {@see RecordingCache}'s docblock
 * for why that matters.
 */
final class CacheFingerprint
{
    public const string CLEAR = 'clear';

    /**
     * `"{op}:{key}"`.
     *
     * The `:` separator is unambiguous only because PSR-16 reserves `:` in a key, so no compliant
     * key can contain one and `of('get', 'a:b')` cannot be confused with `of('get:a', 'b')`. That
     * is a real guarantee rather than an accident -- both this and
     * {@see \Quiote\Replay\Replay\StubbedCache} go through a `Psr\SimpleCache\CacheInterface`
     * -- but it is borrowed from the interface's rule rather than enforced here, so a backend
     * reached through some other contract that permits `:` in keys would need a different
     * separator. Changing it is not free: a fingerprint is what a recorded cassette is matched by,
     * so a new separator makes every existing cassette unmatchable.
     */
    public static function of(string $op, string $key): string
    {
        return $op . ':' . $key;
    }
}
