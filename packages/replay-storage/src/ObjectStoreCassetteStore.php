<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Storage;

use DateTimeImmutable;
use Quiote\Logging\Log;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\ListableCassetteStoreInterface;
use Quiote\Storage\ListableObjectStoreClientInterface;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * A {@see ListableCassetteStoreInterface} over any
 * {@see ListableObjectStoreClientInterface} -- Azure Blob, S3 or GCS. A pod's
 * filesystem does not survive a restart/eviction/scale-down, which is
 * disproportionately likely to be exactly when the interesting request
 * happened.
 *
 * `put()` writes to the deterministic, date-partitioned key
 * {@see CassetteKeyScheme} derives from the cassette's own `recorded_at`.
 * `get()`/`has()`/`delete()`/`slugs()` take only a bare {@see CassetteId} or
 * nothing at all -- neither carries a date -- so they cannot go straight to
 * a key the way {@see \Quiote\Replay\Store\FileCassetteStore}'s directory
 * listing or {@see \Quiote\Replay\Store\Pdo\PdoCassetteStore}'s `WHERE slug
 * = ?` can. Instead they **probe backward hour by hour**, from now, up to
 * `$lookbackHours` (default a Docker-deployment-realistic 48h), checking
 * each hour's deterministic key with a cheap `head()` rather than listing.
 * This makes the base `CassetteStoreInterface` contract honestly work with
 * no further machinery -- slower than an index-assisted lookup for an old
 * cassette, but correct -- and {@see \Quiote\Replay\Index\CassetteIndexInterface}
 * (an explicit key/date hint, or a Log Analytics query) is the *faster* path
 * for exactly the case this probe is slow at, not a requirement for the
 * store to function at all.
 *
 * A stated, deliberate limitation, not a silent one: `slugs()` only
 * enumerates the same `$lookbackHours` window `get()`/`has()`/`delete()`
 * probe, not the object store's entire history. A cassette older than that
 * window exists and is still fetchable by key, but will not appear in
 * `cassette:list`'s output.
 */
final class ObjectStoreCassetteStore implements ListableCassetteStoreInterface
{
    public function __construct(
        private readonly ListableObjectStoreClientInterface $client,
        private readonly CassetteKeyScheme $keyScheme,
        private readonly string $storeAlias,
        private readonly string $containerLabel,
        private readonly int $lookbackHours = 48,
        private readonly CassetteCodec $codec = new CassetteCodec(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function put(CassetteId $id, Cassette $cassette): void
    {
        $recordedAt = self::parseRecordedAt($cassette->meta['recorded_at'] ?? null);
        $key = $this->keyScheme->keyFor($id, $recordedAt, $this->clock->now());
        $payload = $this->codec->encode($cassette);

        $this->client->put($key, $payload);

        $this->logPointer($id, $cassette, $key, strlen($payload));
    }

    public function get(CassetteId $id): ?Cassette
    {
        $key = $this->probeForKey($id);
        if ($key === null) {
            return null;
        }
        $blob = $this->client->get($key);

        return $blob === null ? null : $this->codec->decode($blob);
    }

    public function has(CassetteId $id): bool
    {
        return $this->probeForKey($id) !== null;
    }

    public function delete(CassetteId $id): void
    {
        $key = $this->probeForKey($id);
        if ($key !== null) {
            $this->client->delete($key);
        }
    }

    /**
     * Every slug the last `$lookbackHours` of hour-partitions hold -- see this class's own
     * docblock for why that window, not the object store's entire history.
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $slugs = [];
        $now = $this->clock->now();
        for ($hoursAgo = 0; $hoursAgo <= $this->lookbackHours; $hoursAgo++) {
            $prefix = $this->keyScheme->hourPrefix($now->modify("-{$hoursAgo} hours"));
            $continuationToken = null;
            do {
                $listing = $this->client->listObjects($prefix, continuationToken: $continuationToken);
                foreach ($listing->objects as $object) {
                    $slugs[] = self::slugFromKey($object->key);
                }
                $continuationToken = $listing->nextContinuationToken;
            } while ($continuationToken !== null);
        }

        $unique = array_values(array_unique($slugs));
        sort($unique);

        return $unique;
    }

    /** Walks backward hour by hour from now, checking each hour's deterministic key with head(). */
    private function probeForKey(CassetteId $id): ?string
    {
        $now = $this->clock->now();
        for ($hoursAgo = 0; $hoursAgo <= $this->lookbackHours; $hoursAgo++) {
            $candidate = $this->keyScheme->keyFor($id, $now->modify("-{$hoursAgo} hours"), $now);
            if ($this->client->head($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private static function slugFromKey(string $key): string
    {
        $basename = basename($key);

        return str_ends_with($basename, '.qcast') ? substr($basename, 0, -strlen('.qcast')) : $basename;
    }

    private static function parseRecordedAt(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The pointer log line: the log line is the index. Carries a pointer and nothing else -- no
     * headers, no body, no parameters -- so it stays safe in a log sink with a wider audience
     * than the cassette container itself. Logged at error when the trigger was an error, so it
     * lands in the same query surface the failure itself does; otherwise at info.
     */
    private function logPointer(CassetteId $id, Cassette $cassette, string $key, int $bytes): void
    {
        $body = $cassette->response['body'] ?? null;
        $context = [
            'rid' => $id->raw,
            'cassette_id' => $id->raw,
            'cassette_store' => $this->storeAlias,
            'cassette_container' => $this->containerLabel,
            'cassette_key' => $key,
            'cassette_bytes' => $bytes,
            'cassette_status' => $cassette->response['status'] ?? null,
            'cassette_route' => $cassette->resolved['route'] ?? null,
            'cassette_truncated' => is_array($body) && (bool)($body['truncated'] ?? false),
        ];

        $logger = Log::for($this);
        if (($cassette->meta['trigger'] ?? null) === 'error') {
            $logger->error('cassette stored', $context);
        } else {
            $logger->info('cassette stored', $context);
        }
    }
}
