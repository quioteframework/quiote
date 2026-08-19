<?php

declare(strict_types=1);

namespace Quiote\Replay\Index;

use RuntimeException;

/**
 * Thrown by a {@see CassetteIndexInterface} for a genuine failure -- a malformed hint, a broken
 * query, an auth error, or a pointer that resolved to a key whose object has already expired.
 * Never thrown for "this index has nothing to say for this id/hints", which is a `null` return
 * instead (see {@see CassetteIndexInterface}'s own docblock) -- a chain of indexes must be able to
 * tell "nothing here, try the next one" apart from "this one is broken."
 */
final class CassetteIndexException extends RuntimeException
{
}
