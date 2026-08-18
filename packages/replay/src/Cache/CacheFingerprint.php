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

    public static function of(string $op, string $key): string
    {
        return $op . ':' . $key;
    }
}
