<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Storage;

use DateTimeImmutable;
use Quiote\Logging\Log;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Cassette\RecordedAt;
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
    /**
     * Keys learned from a {@see slugs()} listing, by slug, tried first by {@see candidateKeys()}.
     *
     * A hint about *where* to look, never a claim that something is there: existence is still
     * checked with `head()`, so a hint for an object since deleted costs one wasted round trip and
     * falls through to the probe. Populated only from a listing, which is the case that actually
     * costs -- `cassette:list` decodes every cassette it lists, and each of those lookups otherwise
     * re-probed the whole lookback window even though the listing had just said exactly where the
     * object was. For the default 48-hour window and 200 cassettes that was on the order of ten
     * thousand `head()` calls on top of the listing.
     *
     * Not populated from `put()`: the recorder writing a cassette and the CLI reading one are
     * different processes, so a hint learned there saves nothing real -- and it would make `has()`
     * answer for a cassette outside the lookback window this store documents as its limit.
     *
     * @var array<string, string>
     */
    private array $knownKeys = [];

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

    /**
     * Deletes every copy, not just the newest.
     *
     * `put()` keys by the cassette's own recorded hour, so one slug can legitimately exist in
     * several hour partitions -- a re-recorded correlation id, or a cassette fetched from elsewhere
     * and stored again. Stopping at the first hit meant `cassette:prune` reported a deletion while
     * older copies survived indefinitely, which is the one thing a prune must not do.
     */
    public function delete(CassetteId $id): void
    {
        foreach ($this->candidateKeys($id) as $key) {
            if ($this->client->head($key) !== null) {
                $this->client->delete($key);
            }
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
                    $slug = self::slugFromKey($object->key);
                    $slugs[] = $slug;
                    // The listing already knows where each cassette is, so remembering it lets a
                    // following get()/has() skip the backward probe entirely. Without this,
                    // `cassette:list` against a store holding N cassettes cost N x lookbackHours
                    // head() round trips on top of the listing itself -- for the default 48-hour
                    // window and 200 cassettes, on the order of ten thousand requests.
                    $this->knownKeys[$slug] ??= $object->key;
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
        foreach ($this->candidateKeys($id) as $candidate) {
            if ($this->client->head($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every key this cassette could be under, a listing-learned key first and then newest hour
     * backward.
     *
     * @return list<string>
     */
    private function candidateKeys(CassetteId $id): array
    {
        $now = $this->clock->now();
        $keys = [];
        for ($hoursAgo = 0; $hoursAgo <= $this->lookbackHours; $hoursAgo++) {
            $keys[] = $this->keyScheme->keyFor($id, $now->modify("-{$hoursAgo} hours"), $now);
        }

        $hint = $this->knownKeys[$id->slug] ?? null;
        if ($hint === null) {
            return $keys;
        }

        // Tried first and then dropped from the walk, so a hint inside the lookback window actually
        // saves the hours before it rather than being reached in its own turn.
        return [$hint, ...array_values(array_filter($keys, static fn(string $key): bool => $key !== $hint))];
    }

    private static function slugFromKey(string $key): string
    {
        $basename = basename($key);

        return str_ends_with($basename, '.qcast') ? substr($basename, 0, -strlen('.qcast')) : $basename;
    }

    /**
     * A cassette's `recorded_at` as an instant, via {@see RecordedAt} -- which refuses a relative
     * expression, since `recorded_at` is untrusted cassette content and `"+100 years"` partitioned
     * a cassette into an hour the backward probe can never reach. Falling back to the write time is
     * at worst an hour out and always findable.
     */
    private static function parseRecordedAt(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? RecordedAt::parse($value) : null;
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
