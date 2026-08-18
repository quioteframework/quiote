<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

/**
 * One entry in a request's effect ledger: a single observed side effect
 * (a query, an HTTP call, a cache read, ...), recorded in the order it
 * happened.
 *
 * `$fingerprint` is what {@see \Quiote\Replay\Replay\LedgerMatcher} matches a
 * replayed call against first -- normalized SQL plus a hash of bound
 * parameters for a database call, method+URI+body-hash for HTTP, the key for
 * a cache read -- falling back to `$seq` position within the same
 * {@see EffectKind} when no fingerprint matches. `$call` carries whatever a
 * given recorder needs to describe the call beyond the fingerprint (e.g. the
 * raw SQL and bound parameters), and `$result` the value playback answers
 * with on a match.
 */
final readonly class Effect
{
    /**
     * @param non-negative-int $seq Position in the ledger, in recorded order.
     * @param array<string, mixed> $call Recorder-specific description of the call.
     * @param non-negative-int|null $durationMicros Wall time the real call took, when known.
     */
    public function __construct(
        public int $seq,
        public EffectKind $kind,
        public string $fingerprint,
        public array $call,
        public mixed $result,
        public ?int $durationMicros = null,
    ) {
    }
}
