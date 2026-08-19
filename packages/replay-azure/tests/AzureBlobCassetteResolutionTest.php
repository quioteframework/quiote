<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexChain;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\CassetteIndexInterface;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Store\Azure\Index\LogAnalyticsIndex;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;
use Quiote\Replay\Store\Storage\Index\ExplicitKeyIndex;
use Quiote\Replay\Store\Storage\Index\PrefixScanIndex;
use Quiote\Replay\Store\Storage\ObjectStoreCassetteStore;
use Quiote\Storage\ListableObjectStoreClientInterface;
use Quiote\Storage\ObjectListing;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectSummary;
use Quiote\Support\Clock\FrozenClock;

/**
 * The resolution route a developer gets from `az login` alone: blob read, no account key and no Log
 * Analytics workspace, resolving a cassette id by `--date` or `--key`.
 *
 * `ReplayAzurePluginTest` covers that the three indexes are registered and constructible. This
 * covers that they actually cooperate to find a cassette the store's own backward probe cannot
 * reach -- which is the whole point of the chain, and the thing a wiring mistake would leave
 * broken until someone ran `cassette:fetch` against a real container.
 *
 * Runs against an in-memory object store rather than Azure: what is under test is the key scheme,
 * the probe window and the chain order, none of which involve the wire.
 */
final class AzureBlobCassetteResolutionTest extends TestCase
{
    private const RECORDED_AT = '2026-08-18T09:12:44+00:00';

    private function keyScheme(): CassetteKeyScheme
    {
        return new CassetteKeyScheme('quiote-cassettes', 'prod');
    }

    private function store(
        AzureBlobResolutionFakeClient $client,
        string $now,
        int $lookbackHours = 2,
    ): ObjectStoreCassetteStore {
        return new ObjectStoreCassetteStore(
            $client,
            $this->keyScheme(),
            storeAlias: 'azure-blob',
            containerLabel: 'quiote-cassettes',
            lookbackHours: $lookbackHours,
            clock: new FrozenClock(self::timestamp($now)),
        );
    }

    /** `strtotime()` returns `int|false`; FrozenClock takes an int. */
    private static function timestamp(string $when): int
    {
        $timestamp = strtotime($when);
        self::assertIsInt($timestamp, "Unparseable test timestamp: $when");

        return $timestamp;
    }

    private function cassette(string $rawId, string $recordedAt = self::RECORDED_AT): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId, 'recorded_at' => $recordedAt, 'trigger' => 'error'],
            request: ['method' => 'GET', 'uri' => '/orders/42'],
            resolved: ['route' => 'orders.show'],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 500, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: null,
            log: null,
        );
    }

    /**
     * The chain exactly as `ReplayAzurePlugin` contributes it, with no workspace configured.
     *
     * @return list<CassetteIndexInterface>
     */
    private function chain(AzureBlobResolutionFakeClient $client): array
    {
        return [
            new ExplicitKeyIndex($client),
            new LogAnalyticsIndex(null),
            new PrefixScanIndex($client, $this->keyScheme()),
        ];
    }

    public function testWithNoWorkspaceTheLogAnalyticsIndexDeclinesWithoutABlobClient(): void
    {
        // The common configuration for --date/--key resolution: no workspace at all. Building a
        // blob client and its credential for an index that declines every call is work with no
        // result, repeated on every resolution attempt.
        $index = new LogAnalyticsIndex(null);

        $this->assertNull($index->resolve(CassetteId::fromRaw('SUX2020'), new IndexHints()));
    }

    public function testACassetteOutsideTheProbeWindowIsResolvedByDate(): void
    {
        $client = new AzureBlobResolutionFakeClient();
        $this->store($client, '2026-08-18T09:12:44+00:00')->put(CassetteId::fromRaw('SUX2020'), $this->cassette('SUX2020'));

        // Two days later: far outside the store's own 2-hour backward probe, so only the index
        // chain can find it. This is the case that makes the chain worth having.
        $id = CassetteId::fromRaw('SUX2020');
        $this->assertNull($this->store($client, '2026-08-20T09:12:44+00:00')->get($id), 'Guard: the store alone must not find it.');

        $resolved = CassetteIndexChain::resolve($this->chain($client), $id, new IndexHints(date: '2026-08-18'));

        $this->assertSame('SUX2020', $resolved->meta['id']);
        $this->assertSame(500, $resolved->response['status']);
    }

    public function testADateHintNarrowsToOneHeadPerHourRatherThanListingEachBucket(): void
    {
        $client = new AzureBlobResolutionFakeClient();
        $this->store($client, '2026-08-18T09:12:44+00:00')->put(CassetteId::fromRaw('SUX2020'), $this->cassette('SUX2020'));
        $client->headCalls = [];

        CassetteIndexChain::resolve($this->chain($client), CassetteId::fromRaw('SUX2020'), new IndexHints(date: '2026-08-18', hour: '09'));

        // The key is fully determined by the slug and the hour, so one request answers it.
        $this->assertCount(1, $client->headCalls);
    }

    public function testAnExplicitKeyResolvesWithoutAnyListingAtAll(): void
    {
        $client = new AzureBlobResolutionFakeClient();
        $this->store($client, '2026-08-18T09:12:44+00:00')->put(CassetteId::fromRaw('SUX2020'), $this->cassette('SUX2020'));
        $key = array_keys($client->objects)[0];
        $client->listCalls = 0;

        $resolved = CassetteIndexChain::resolve($this->chain($client), CassetteId::fromRaw('SUX2020'), new IndexHints(key: $key));

        $this->assertSame('SUX2020', $resolved->meta['id']);
        $this->assertSame(0, $client->listCalls, 'An exact key needs no enumeration.');
    }

    public function testTheKeyTheRecorderLogsIsTheKeyThatResolves(): void
    {
        // The workflow this supports: the pointer log line names cassette_key, a developer pastes
        // it as --key. So the key put() writes has to be the one ExplicitKeyIndex can fetch.
        $client = new AzureBlobResolutionFakeClient();
        $this->store($client, '2026-08-18T09:12:44+00:00')->put(CassetteId::fromRaw('SUX2020'), $this->cassette('SUX2020'));

        $this->assertSame(['quiote-cassettes/prod/2026/08/18/09/SUX2020.qcast'], array_keys($client->objects));
        $this->assertNotNull(
            (new ExplicitKeyIndex($client))->resolve(
                CassetteId::fromRaw('SUX2020'),
                new IndexHints(key: 'quiote-cassettes/prod/2026/08/18/09/SUX2020.qcast'),
            ),
        );
    }

    public function testAWrongDateDeclinesRatherThanResolvingSomethingElse(): void
    {
        $client = new AzureBlobResolutionFakeClient();
        $this->store($client, '2026-08-18T09:12:44+00:00')->put(CassetteId::fromRaw('SUX2020'), $this->cassette('SUX2020'));

        $this->expectException(CassetteIndexException::class);
        $this->expectExceptionMessageMatches('/No index could resolve cassette "SUX2020"/');
        CassetteIndexChain::resolve($this->chain($client), CassetteId::fromRaw('SUX2020'), new IndexHints(date: '2026-08-17'));
    }

    public function testAnIdThatWasNeverRecordedFails(): void
    {
        $client = new AzureBlobResolutionFakeClient();

        $this->expectException(CassetteIndexException::class);
        $this->expectExceptionMessageMatches('/No index could resolve cassette "NOPE"/');
        CassetteIndexChain::resolve($this->chain($client), CassetteId::fromRaw('NOPE'), new IndexHints(date: '2026-08-18'));
    }

    public function testWithNoHintAtAllAndNoWorkspaceEveryIndexDeclines(): void
    {
        // Worth pinning as the honest limit of this configuration: a bare id resolves only within
        // the store's own probe window, or through a workspace. The failure names the shape of the
        // problem rather than looking like "not recorded".
        $client = new AzureBlobResolutionFakeClient();
        $this->store($client, '2026-08-18T09:12:44+00:00')->put(CassetteId::fromRaw('SUX2020'), $this->cassette('SUX2020'));

        $this->expectException(CassetteIndexException::class);
        $this->expectExceptionMessageMatches('/no cassette index is configured, or none had a matching hint to try/');
        CassetteIndexChain::resolve($this->chain($client), CassetteId::fromRaw('SUX2020'), new IndexHints());
    }

    public function testACassetteInsideTheProbeWindowNeedsNoHint(): void
    {
        // The other half of the honest limit: recent enough, and the store finds it unaided.
        $client = new AzureBlobResolutionFakeClient();
        $id = CassetteId::fromRaw('SUX2020');
        $store = $this->store($client, '2026-08-18T09:12:44+00:00');
        $store->put($id, $this->cassette('SUX2020'));

        $this->assertNotNull($this->store($client, '2026-08-18T10:30:00+00:00')->get($id));
    }

    public function testTheEnvSegmentOfAKeyCanNameAnotherDeploymentsEnvironment(): void
    {
        // The reader's own core.environment is readonly once bootstrapped and is not the one the
        // recording deployment ran under, so a laptop reading production cassettes has to be able
        // to say which environment's keys it wants.
        $scheme = new CassetteKeyScheme('quiote-cassettes', 'production');

        $this->assertSame(
            'quiote-cassettes/production/2026/08/18/09/SUX2020.qcast',
            $scheme->keyFor(CassetteId::fromRaw('SUX2020'), new DateTimeImmutable('2026-08-18T09:12:44+00:00'), new DateTimeImmutable('2026-08-20T00:00:00+00:00')),
        );
    }

}

/**
 * An in-memory object store, counting the calls a test wants to assert about. Only the surface
 * `ObjectStoreCassetteStore` and the two blob-backed indexes actually use.
 */
final class AzureBlobResolutionFakeClient implements ListableObjectStoreClientInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $headCalls = [];

    public int $listCalls = 0;

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->objects[$key] ?? null;
    }

    #[\Override]
    public function put(string $key, string $body): void
    {
        $this->objects[$key] = $body;
    }

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    #[\Override]
    public function head(string $key): ?ObjectMetadata
    {
        $this->headCalls[] = $key;

        return isset($this->objects[$key]) ? new ObjectMetadata(strlen($this->objects[$key]), null, null) : null;
    }

    #[\Override]
    public function listObjects(string $prefix = '', string $delimiter = '', ?string $continuationToken = null, int $maxKeys = 1000): ObjectListing
    {
        $this->listCalls++;
        $matching = array_values(array_filter(array_keys($this->objects), static fn(string $k): bool => str_starts_with($k, $prefix)));
        sort($matching);

        if ($delimiter === '/') {
            $prefixes = [];
            foreach ($matching as $key) {
                $next = strpos($key, '/', strlen($prefix));
                if ($next !== false) {
                    $prefixes[] = substr($key, 0, $next + 1);
                }
            }
            sort($prefixes);

            return new ObjectListing([], array_values(array_unique($prefixes)), null);
        }

        return new ObjectListing(
            array_map(fn(string $key): ObjectSummary => new ObjectSummary($key, strlen($this->objects[$key]), null, null), $matching),
            [],
            null,
        );
    }
}
