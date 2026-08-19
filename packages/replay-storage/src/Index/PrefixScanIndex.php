<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Storage\Index;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\CassetteIndexInterface;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;
use Quiote\Storage\ListableObjectStoreClientInterface;

/**
 * Reconstructs a key prefix from a `--date` (and, optionally, `--hour`) hint and enumerates it
 * with `listObjects()`, needing no index service or Log Analytics access -- only blob read, which
 * makes it the right fallback for a developer who has a storage RBAC grant but not a workspace
 * one. Declines (returns null) when no `--date` hint was given; a date/hour that parses but
 * matches nothing is also a decline, since "not recorded that day" is a legitimate outcome, not a
 * broken index.
 *
 * With only `--date`, the day's hour buckets are discovered first via a delimited listing (one
 * request returns one common prefix per hour, per {@see CassetteKeyScheme::dayPrefix()}), then
 * each hour is scanned for a matching slug -- the same "browse what happened this day" technique
 * `cassette:list` itself could use against an object store, just narrowed to one id.
 */
final readonly class PrefixScanIndex implements CassetteIndexInterface
{
    public function __construct(
        private ListableObjectStoreClientInterface $client,
        private CassetteKeyScheme $keyScheme,
        private CassetteCodec $codec = new CassetteCodec(),
    ) {
    }

    #[\Override]
    public function resolve(CassetteId $id, IndexHints $hints): ?Cassette
    {
        if ($hints->date === null || $hints->date === '') {
            return null;
        }

        // Validated as strictly as --hour is. `new DateTimeImmutable()` takes `tomorrow` and
        // `+1 week` as readily as a date, for an option documented as YYYY-MM-DD -- and a relative
        // expression silently scans a prefix the developer did not ask for.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hints->date) !== 1) {
            throw new CassetteIndexException(sprintf('"--date=%s" must be a YYYY-MM-DD calendar date.', $hints->date));
        }
        try {
            $day = new DateTimeImmutable($hints->date, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            throw new CassetteIndexException(sprintf('Could not parse "--date=%s" as a date: %s', $hints->date, $e->getMessage()), 0, $e);
        }

        $hourPrefixes = $hints->hour !== null && $hints->hour !== ''
            ? [$this->keyScheme->hourPrefix($this->withHour($day, $hints->hour))]
            : $this->discoverHourPrefixes($day);

        foreach ($hourPrefixes as $hourPrefix) {
            $key = $this->findKeyInHour($hourPrefix, $id);
            if ($key !== null) {
                $blob = $this->client->get($key);

                return $blob === null ? null : $this->codec->decode($blob);
            }
        }

        return null;
    }

    private function withHour(DateTimeImmutable $day, string $hour): DateTimeImmutable
    {
        if (!ctype_digit($hour) || (int) $hour > 23) {
            throw new CassetteIndexException(sprintf('"--hour=%s" must be 00-23.', $hour));
        }

        return $day->setTime((int) $hour, 0);
    }

    /** @return list<string> */
    private function discoverHourPrefixes(DateTimeImmutable $day): array
    {
        $dayPrefix = $this->keyScheme->dayPrefix($day);
        $prefixes = [];
        $continuationToken = null;
        do {
            $listing = $this->client->listObjects($dayPrefix, '/', $continuationToken);
            foreach ($listing->commonPrefixes as $prefix) {
                $prefixes[] = $prefix;
            }
            $continuationToken = $listing->nextContinuationToken;
        } while ($continuationToken !== null);

        return $prefixes;
    }

    /**
     * One `head()` on the key this hour would hold the cassette under, rather than a paginated
     * listing of the hour compared by basename.
     *
     * The key is fully determined by the slug and the hour -- {@see CassetteKeyScheme::keyFor()}
     * builds it -- so enumerating the bucket to find a name already known was work with no
     * information in it. A busy hour could be thousands of objects across several pages; it is now
     * one request, and a whole day's scan is 24 rather than 24 paginated listings.
     */
    private function findKeyInHour(string $hourPrefix, CassetteId $id): ?string
    {
        $key = $hourPrefix . $id->slug . '.qcast';

        return $this->client->head($key) !== null ? $key : null;
    }
}
