<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

use RuntimeException;

/**
 * A cassette payload could not be decoded: corrupt/truncated gzip, invalid
 * JSON, a missing required section, or a schema version this codec does not
 * understand. No silent best-effort parsing -- a partially understood
 * cassette produces a wrong test, which is worse than no test.
 */
final class CassetteCodecException extends RuntimeException
{
}
