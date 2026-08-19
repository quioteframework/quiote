<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use RuntimeException;

/**
 * A cassette cannot be replayed as given: no request was captured to
 * replay (recorded under `#[NoRecord]`, or with `replay.capture_body`
 * disabled), or a safety guard in {@see ReplayEngine} refused to run it.
 */
final class ReplayException extends RuntimeException
{
}
