<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Quiote\Replay\Cassette\CassetteId;

/**
 * Derives an object-store key from a cassette id and the hour it was recorded in:
 *
 *   {prefix}/{env}/{yyyy}/{mm}/{dd}/{hh}/{id}.qcast
 *
 * Time-partitioned so a lifecycle rule and a "what happened this hour"
 * prefix listing are both trivial, and so a flat container holding a year
 * of cassettes never has to be enumerated whole. Always partitions by the
 * cassette's own recorded hour, forced to UTC -- never the current time and
 * never the server's local timezone -- so a cassette fetched a day later
 * resolves to the same key regardless of which timezone the fetching
 * process happens to run in, and two servers in different timezones never
 * partition the same instant into different hour buckets.
 *
 * Uses {@see CassetteId::$slug}, never `$id->raw`, for the final path
 * segment: an adopted correlation id can carry `/`, `.` or `..` straight
 * through `Quiote\Support\CorrelationId::sanitize()` (verified against that
 * class, not assumed), and `$slug` is what already reduces that to a safe,
 * bounded identifier -- see `CassetteId`'s own docblock.
 */
final readonly class CassetteKeyScheme
{
    public function __construct(
        private string $prefix,
        private string $env,
    ) {
    }

    /** The key a cassette recorded at $recordedAt (or, absent that, $fallback) is written under. */
    public function keyFor(CassetteId $id, ?DateTimeImmutable $recordedAt, DateTimeImmutable $fallback): string
    {
        return $this->hourPrefix($recordedAt ?? $fallback) . $id->slug . '.qcast';
    }

    /** The key prefix for every cassette recorded during $dt's hour, for a bounded backward probe or a listing. */
    public function hourPrefix(DateTimeImmutable $dt): string
    {
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));

        return $this->dayPrefix($utc) . $utc->format('H') . '/';
    }

    /**
     * The key prefix for every cassette recorded during $dt's UTC calendar day -- one level up
     * from {@see hourPrefix()}, for a delimited `listObjects()` call that enumerates that day's
     * hour buckets as common prefixes rather than every object in it.
     */
    public function dayPrefix(DateTimeImmutable $dt): string
    {
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));

        return sprintf(
            '%s/%s/%s/%s/%s/',
            trim($this->prefix, '/'),
            $this->env,
            $utc->format('Y'),
            $utc->format('m'),
            $utc->format('d'),
        );
    }
}
