<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

/**
 * Which way an {@see EffectLedger} is being used.
 *
 * That ledger's docblock has always said one instance serves exactly one direction at a time -- a
 * fresh ledger for recording only ever calls `record()`, one built from a cassette only ever calls
 * `match()`. This makes the claim checkable, and more importantly makes it *readable by a
 * collaborator*: a driver decorator installed permanently on a connection has to know which of the
 * two it is looking at, because for one it should execute the query and record what happened, and
 * for the other it must not touch the connection at all and answer from what was recorded.
 *
 * Without that distinction the decorators could only ever record, which is why every `Stubbed*`
 * class in this package sat unwired: there was nothing to tell an installed decorator that the
 * request currently flowing through it was a replay.
 */
enum LedgerDirection
{
    /** Observing a real request: `record()` appends what actually happened. */
    case Recording;

    /** Serving a recorded request: `match()` answers from the cassette, and nothing is performed. */
    case Replaying;
}
