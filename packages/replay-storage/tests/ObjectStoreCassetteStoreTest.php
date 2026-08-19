<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\LogRegistry;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;
use Quiote\Replay\Store\Storage\ObjectStoreCassetteStore;
use Quiote\Storage\ListableObjectStoreClientInterface;
use Quiote\Storage\ObjectListing;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectSummary;
use Quiote\Support\Clock\FrozenClock;

/** Accepts everything, so what a call decides to emit is what gets recorded. */
final class ObjectStoreCassetteStoreCapturingSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $captured = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return true;
    }

    public function emit(LogEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function flush(): void
    {
    }
}

/** A minimal in-memory ListableObjectStoreClientInterface, mirroring FakeListableObjectStore. */
final class ObjectStoreCassetteStoreFakeClient implements ListableObjectStoreClientInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $headCalls = [];

    public function get(string $key): ?string
    {
        return $this->objects[$key] ?? null;
    }

    public function put(string $key, string $body): void
    {
        $this->objects[$key] = $body;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function head(string $key): ?ObjectMetadata
    {
        $this->headCalls[] = $key;

        return isset($this->objects[$key]) ? new ObjectMetadata(strlen($this->objects[$key]), null, null) : null;
    }

    #[\Override]
    public function listObjects(string $prefix = '', string $delimiter = '', ?string $continuationToken = null, int $maxKeys = 1000): ObjectListing
    {
        $matching = array_values(array_filter(array_keys($this->objects), static fn(string $k): bool => str_starts_with($k, $prefix)));
        sort($matching);

        return new ObjectListing(
            array_map(fn(string $key): ObjectSummary => new ObjectSummary($key, strlen($this->objects[$key]), null, null), $matching),
            [],
            null,
        );
    }
}

final class ObjectStoreCassetteStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        LogRegistry::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $response
     */
    private function cassette(
        array $meta = ['id' => 'CRX2050'],
        array $response = ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
    ): Cassette {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: $meta,
            request: ['method' => 'GET', 'uri' => '/'],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: $response,
            exception: null,
            log: null,
        );
    }

    /** @return array{0: ObjectStoreCassetteStoreFakeClient, 1: ObjectStoreCassetteStore} */
    private function store(FrozenClock $clock, int $lookbackHours = 2): array
    {
        $client = new ObjectStoreCassetteStoreFakeClient();
        $store = new ObjectStoreCassetteStore(
            $client,
            new CassetteKeyScheme('quiote-cassettes', 'prod'),
            storeAlias: 'azure-blob',
            containerLabel: 'quiote-cassettes',
            lookbackHours: $lookbackHours,
            clock: $clock,
        );

        return [$client, $store];
    }

    public function testPutWritesToTheDatePartitionedKey(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [$client, $store] = $this->store($clock);

        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-18T09:12:44+00:00']));

        $this->assertSame(
            ['quiote-cassettes/prod/2026/08/18/09/CRX2050.qcast'],
            array_keys($client->objects),
        );
    }

    public function testPutFallsBackToNowWhenRecordedAtIsMissing(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [$client, $store] = $this->store($clock);

        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette(['id' => 'CRX2050']));

        $this->assertSame(['quiote-cassettes/prod/2026/08/18/09/CRX2050.qcast'], array_keys($client->objects));
    }

    public function testKeyPartitionsByUtcRegardlessOfTheRecordedAtOffset(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [$client, $store] = $this->store($clock);

        // 23:30 in UTC+5 is 18:30 UTC on the same calendar day.
        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-18T23:30:00+05:00']));

        $this->assertSame(['quiote-cassettes/prod/2026/08/18/18/CRX2050.qcast'], array_keys($client->objects));
    }

    public function testPutThenGetInTheSameHourRoundTrips(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock);
        $id = CassetteId::fromRaw('CRX2050');

        $store->put($id, $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-18T09:12:44+00:00']));
        $loaded = $store->get($id);

        $this->assertNotNull($loaded);
        $this->assertSame('CRX2050', $loaded->meta['id']);
    }

    public function testGetFindsACassetteRecordedAnHourAgoWithinTheLookbackWindow(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock, lookbackHours: 2);
        $id = CassetteId::fromRaw('CRX2050');
        $store->put($id, $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-18T08:05:00+00:00']));

        $this->assertTrue($store->has($id));
        $this->assertNotNull($store->get($id));
    }

    public function testGetDoesNotFindACassetteOlderThanTheLookbackWindow(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [$client, $store] = $this->store($clock, lookbackHours: 2);
        $id = CassetteId::fromRaw('CRX2050');
        // Recorded 10 hours ago -- outside a 2-hour lookback.
        $store->put($id, $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-17T23:12:44+00:00']));

        $this->assertFalse($store->has($id));
        $this->assertNull($store->get($id));
        // Each probe checks exactly lookbackHours+1 candidates (0, 1, 2 hours ago), not an
        // unbounded scan -- has() and get() above each ran one full probe, hence x2.
        $this->assertCount(6, $client->headCalls);
    }

    public function testGetOnAnUnknownIdReturnsNull(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock);

        $this->assertNull($store->get(CassetteId::fromRaw('never-stored')));
    }

    public function testDeleteRemovesAFoundCassette(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock);
        $id = CassetteId::fromRaw('CRX2050');
        $store->put($id, $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-18T09:12:44+00:00']));

        $store->delete($id);

        $this->assertFalse($store->has($id));
    }

    public function testDeleteOfAnUnknownIdIsNotAnError(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock);

        $store->delete(CassetteId::fromRaw('never-stored'));

        $this->addToAssertionCount(1);
    }

    public function testSlugsListsCassettesWithinTheLookbackWindow(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock, lookbackHours: 2);
        $store->put(CassetteId::fromRaw('AAA'), $this->cassette(['id' => 'AAA', 'recorded_at' => '2026-08-18T09:00:00+00:00']));
        $store->put(CassetteId::fromRaw('BBB'), $this->cassette(['id' => 'BBB', 'recorded_at' => '2026-08-18T08:00:00+00:00']));

        $this->assertSame(['AAA', 'BBB'], $store->slugs());
    }

    public function testSlugsExcludesCassettesOlderThanTheLookbackWindow(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock, lookbackHours: 1);
        $store->put(CassetteId::fromRaw('RECENT'), $this->cassette(['id' => 'RECENT', 'recorded_at' => '2026-08-18T09:00:00+00:00']));
        $store->put(CassetteId::fromRaw('OLD'), $this->cassette(['id' => 'OLD', 'recorded_at' => '2026-08-17T09:00:00+00:00']));

        $this->assertSame(['RECENT'], $store->slugs());
    }

    public function testPutLogsAnInfoPointerLineByDefault(): void
    {
        $sink = new ObjectStoreCassetteStoreCapturingSink();
        Log::addSink($sink);
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock);

        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette(
            ['id' => 'CRX2050', 'recorded_at' => '2026-08-18T09:12:44+00:00', 'trigger' => 'always'],
        ));

        $this->assertCount(1, $sink->captured);
        $event = $sink->captured[0];
        $this->assertSame(Level::Info, $event->level);
        $this->assertSame('cassette stored', $event->renderMessage());
        $this->assertSame('CRX2050', $event->properties['cassette_id']);
        $this->assertSame('azure-blob', $event->properties['cassette_store']);
        $this->assertSame('quiote-cassettes', $event->properties['cassette_container']);
        $this->assertSame('quiote-cassettes/prod/2026/08/18/09/CRX2050.qcast', $event->properties['cassette_key']);
        $this->assertFalse($event->properties['cassette_truncated']);
    }

    public function testPutLogsAnErrorPointerLineWhenTheTriggerWasError(): void
    {
        $sink = new ObjectStoreCassetteStoreCapturingSink();
        Log::addSink($sink);
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock);

        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette(
            ['id' => 'CRX2050', 'recorded_at' => '2026-08-18T09:12:44+00:00', 'trigger' => 'error'],
            ['status' => 500, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
        ));

        $this->assertCount(1, $sink->captured);
        $this->assertSame(Level::Error, $sink->captured[0]->level);
        $this->assertSame(500, $sink->captured[0]->properties['cassette_status']);
    }

    public function testDeleteRemovesEveryCopyAcrossHourPartitions(): void
    {
        // One slug can legitimately sit in several hour partitions: put() keys by the cassette's
        // own recorded hour, so a re-recorded correlation id writes a second object. Stopping at
        // the first hit meant prune reported a deletion while older copies survived.
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [$client, $store] = $this->store($clock, lookbackHours: 6);
        $id = CassetteId::fromRaw('CRX2050');

        $store->put($id, $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-18T09:12:44+00:00']));
        $store->put($id, $this->cassette(['id' => 'CRX2050', 'recorded_at' => '2026-08-18T06:30:00+00:00']));
        $this->assertCount(2, $client->objects, 'Guard: two distinct hour partitions.');

        $store->delete($id);

        $this->assertSame([], $client->objects);
        $this->assertFalse($store->has($id));
    }

    public function testAListingTeachesTheStoreWhereEachCassetteIsSoLookupsStopProbing(): void
    {
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [$client, $store] = $this->store($clock, lookbackHours: 48);
        $id = CassetteId::fromRaw('OLD1');
        // Recorded 40 hours ago: inside the window, but 40 head() calls deep into the probe.
        $store->put($id, $this->cassette(['id' => 'OLD1', 'recorded_at' => '2026-08-16T17:12:44+00:00']));

        $store->slugs();
        $client->headCalls = [];
        $this->assertNotNull($store->get($id));

        // One head() -- the key the listing already reported -- rather than 41.
        $this->assertCount(1, $client->headCalls);
    }

    public function testAStaleKeyHintFallsThroughToTheProbeRatherThanClaimingAMiss(): void
    {
        // The hint says where to look, never that something is there.
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [$client, $store] = $this->store($clock, lookbackHours: 6);
        $id = CassetteId::fromRaw('MOVED');
        $store->put($id, $this->cassette(['id' => 'MOVED', 'recorded_at' => '2026-08-18T06:30:00+00:00']));
        $store->slugs();

        // Re-recorded into a different hour, and the old object removed: the hint is now wrong.
        $client->objects = [];
        $store->put($id, $this->cassette(['id' => 'MOVED', 'recorded_at' => '2026-08-18T09:12:44+00:00']));

        $this->assertNotNull($store->get($id));
    }

    public function testARelativeRecordedAtIsNotUsedToPartitionTheKey(): void
    {
        // recorded_at is untrusted cassette content, and DateTimeImmutable takes "+100 years" as
        // readily as an instant -- which partitioned the cassette into an hour the backward probe
        // can never reach.
        $clock = new FrozenClock(strtotime('2026-08-18T09:12:44+00:00'));
        [, $store] = $this->store($clock, lookbackHours: 6);
        $id = CassetteId::fromRaw('RELATIVE');

        $store->put($id, $this->cassette(['id' => 'RELATIVE', 'recorded_at' => '+100 years']));

        // Falls back to the write time, so it is still findable.
        $this->assertTrue($store->has($id));
        $this->assertNotNull($store->get($id));
    }
}
