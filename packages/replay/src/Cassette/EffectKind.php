<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

/**
 * The kind of side effect one {@see Effect} ledger entry records. Each value
 * is the seam a recording decorator observes and a stub answers from during
 * isolated replay.
 *
 * `Mail` has no recorder yet -- Quiote has no mail subsystem to instrument --
 * but the case is reserved now so a future one needs no cassette format
 * change.
 */
enum EffectKind: string
{
    case Db = 'db';
    case Http = 'http';
    case Cache = 'cache';
    case Queue = 'queue';
    case Mail = 'mail';
    case Clock = 'clock';
    case Entropy = 'entropy';
    case Env = 'env';
    case Session = 'session';
}
