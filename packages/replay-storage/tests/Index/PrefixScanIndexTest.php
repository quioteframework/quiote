<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;
use Quiote\Replay\Store\Storage\Index\PrefixScanIndex;
use Quiote\Storage\ListableObjectStoreClientInterface;
use Quiote\Storage\ObjectListing;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectSummary;

final class PrefixScanIndexTest extends TestCase
{
    /** @param array<string, mixed> $meta */
    private function cassette(array $meta = ['id' => 'CRX2050']): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: $meta,
            request: ['method' => 'GET', 'uri' => '/'],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200, 'headers' => [], 'body' => ['encoding' => 'utf8', 'content' => '', 'truncated' => false]],
            exception: null,
            log: null,
        );
    }

    private function keyScheme(): CassetteKeyScheme
    {
        return new CassetteKeyScheme('quiote-cassettes', 'prod');
    }

    public function testDeclinesWhenNoDateHintIsGiven(): void
    {
        $index = new PrefixScanIndex(new PrefixScanIndexFakeClient([]), $this->keyScheme());

        $this->assertNull($index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints()));
    }

    public function testResolvesWithinAGivenDateAndHour(): void
    {
        $codec = new CassetteCodec();
        $client = new PrefixScanIndexFakeClient(['quiote-cassettes/prod/2026/08/18/09/CRX2050.qcast' => $codec->encode($this->cassette())]);
        $index = new PrefixScanIndex($client, $this->keyScheme(), $codec);

        $cassette = $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints(date: '2026-08-18', hour: '09'));

        $this->assertNotNull($cassette);
        $this->assertSame('CRX2050', $cassette->meta['id']);
    }

    public function testResolvesByScanningEveryHourWhenNoHourHintIsGiven(): void
    {
        $codec = new CassetteCodec();
        $client = new PrefixScanIndexFakeClient(['quiote-cassettes/prod/2026/08/18/14/CRX2050.qcast' => $codec->encode($this->cassette())]);
        $index = new PrefixScanIndex($client, $this->keyScheme(), $codec);

        $cassette = $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints(date: '2026-08-18'));

        $this->assertNotNull($cassette);
    }

    public function testDeclinesWhenTheDateHasNoMatchingCassette(): void
    {
        $index = new PrefixScanIndex(new PrefixScanIndexFakeClient([]), $this->keyScheme());

        $this->assertNull($index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints(date: '2026-08-18')));
    }

    public function testThrowsOnAnUnparsableDate(): void
    {
        $index = new PrefixScanIndex(new PrefixScanIndexFakeClient([]), $this->keyScheme());

        $this->expectException(CassetteIndexException::class);
        $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints(date: 'not-a-date'));
    }

    public function testThrowsOnAnOutOfRangeHour(): void
    {
        $index = new PrefixScanIndex(new PrefixScanIndexFakeClient([]), $this->keyScheme());

        $this->expectException(CassetteIndexException::class);
        $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints(date: '2026-08-18', hour: '25'));
    }
}

final class PrefixScanIndexFakeClient implements ListableObjectStoreClientInterface
{
    /** @param array<string, string> $objects */
    public function __construct(private readonly array $objects)
    {
    }

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->objects[$key] ?? null;
    }

    #[\Override]
    public function put(string $key, string $body): void
    {
        throw new \LogicException('not used by this test');
    }

    #[\Override]
    public function delete(string $key): void
    {
        throw new \LogicException('not used by this test');
    }

    #[\Override]
    public function head(string $key): ?ObjectMetadata
    {
        throw new \LogicException('not used by this test');
    }

    #[\Override]
    public function listObjects(string $prefix = '', string $delimiter = '', ?string $continuationToken = null, int $maxKeys = 1000): ObjectListing
    {
        if ($delimiter === '/') {
            $hourPrefixes = array_values(array_unique(array_map(
                static fn(string $key): string => substr($key, 0, strpos($key, '/', strlen($prefix)) + 1),
                array_filter(array_keys($this->objects), static fn(string $k): bool => str_starts_with($k, $prefix)),
            )));
            sort($hourPrefixes);

            return new ObjectListing([], $hourPrefixes, null);
        }

        $matching = array_values(array_filter(array_keys($this->objects), static fn(string $k): bool => str_starts_with($k, $prefix)));
        sort($matching);

        return new ObjectListing(
            array_map(fn(string $key): ObjectSummary => new ObjectSummary($key, strlen($this->objects[$key]), null, null), $matching),
            [],
            null,
        );
    }
}
