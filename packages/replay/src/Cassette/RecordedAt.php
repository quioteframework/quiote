<?php

declare(strict_types=1);

namespace Quiote\Replay\Cassette;

use DateTimeImmutable;
use Exception;

/**
 * Reads a cassette's `recorded_at` as an instant.
 *
 * One implementation because three call sites need the same rule and had three different ones:
 * `cassette:list` compared the raw strings, `cassette:prune` used `strtotime()`, and
 * `ObjectStoreCassetteStore` used a bare `new DateTimeImmutable()`.
 *
 * Two things it insists on. Comparison is by instant, not by string: `RecorderMiddleware` formats
 * `recorded_at` in PHP's default timezone rather than forcing UTC, so two cassettes recorded either
 * side of an offset difference sort wrong under a string comparison even though both are valid
 * ISO-8601. And only an *absolute* instant is accepted: `recorded_at` is untrusted cassette content,
 * while both `strtotime()` and `DateTimeImmutable` take `now`, `tomorrow` and `+100 years` as
 * readily as a timestamp -- so a relative expression there made a cassette sort wherever it liked,
 * partition into an hour a backward probe can never reach, and never match a retention cutoff.
 */
final class RecordedAt
{
    /** A leading `YYYY-MM-DD` followed by a date/time separator: what every absolute form starts with. */
    private const ABSOLUTE_PREFIX = '/^\d{4}-\d{2}-\d{2}([T ]|$)/';

    /** The instant $value names, or null when it names none or is not absolute. */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || preg_match(self::ABSOLUTE_PREFIX, $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /** {@see parse()} as a Unix timestamp, for sorting and cutoff comparisons. */
    public static function timestamp(?string $value): ?int
    {
        return self::parse($value)?->getTimestamp();
    }
}
