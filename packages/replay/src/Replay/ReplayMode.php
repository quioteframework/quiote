<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

/**
 * How {@see ReplayEngine} runs a cassette.
 *
 * {@see Isolated} is the default because it is the safe one, and because it is what the feature is
 * for: a cassette is worth having because it lets a production request be examined somewhere that is
 * not production. {@see Live} exists for the case isolation cannot serve -- confirming that a fix
 * actually works against real collaborators -- and is gated behind `replay.allow_live` and
 * `--force`.
 */
enum ReplayMode: string
{
    /**
     * Every ledger-backed subsystem answered from the cassette's own recorded effects, nothing
     * performed. Refuses to run at all if the database cannot be isolated -- see
     * {@see IsolatedReplay}.
     */
    case Isolated = 'isolated';

    /**
     * Dispatched against whatever the context is really configured with, re-performing the
     * request's side effects for real.
     */
    case Live = 'live';
}
