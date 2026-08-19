<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Store\Azure\Index\LogAnalyticsIndex;
use Quiote\Storage\Azure\AzureMonitorQueryClientInterface;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectStoreClientInterface;

final class LogAnalyticsIndexTest extends TestCase
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

    public function testDeclinesWhenNoQueryClientIsConfigured(): void
    {
        $index = new LogAnalyticsIndex(null, new LogAnalyticsIndexFakeObjectClient([]));

        $this->assertNull($index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints()));
    }

    public function testDeclinesWhenTheQueryFindsNoPointer(): void
    {
        $index = new LogAnalyticsIndex(new LogAnalyticsIndexFakeQueryClient([]), new LogAnalyticsIndexFakeObjectClient([]));

        $this->assertNull($index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints()));
    }

    public function testResolvesTheObjectAtThePointersKey(): void
    {
        $codec = new CassetteCodec();
        $queryClient = new LogAnalyticsIndexFakeQueryClient([['cassette_key' => 'prod/2026/08/18/09/CRX2050.qcast']]);
        $objectClient = new LogAnalyticsIndexFakeObjectClient(['prod/2026/08/18/09/CRX2050.qcast' => $codec->encode($this->cassette())]);
        $index = new LogAnalyticsIndex($queryClient, $objectClient, codec: $codec);

        $cassette = $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints());

        $this->assertNotNull($cassette);
        $this->assertSame('CRX2050', $cassette->meta['id']);
        $this->assertStringContainsString('CRX2050', $queryClient->lastQuery ?? '');
    }

    public function testThrowsWhenThePointerIsFoundButTheObjectHasExpired(): void
    {
        $queryClient = new LogAnalyticsIndexFakeQueryClient([['cassette_key' => 'prod/2026/08/18/09/CRX2050.qcast']]);
        $index = new LogAnalyticsIndex($queryClient, new LogAnalyticsIndexFakeObjectClient([]));

        $this->expectException(CassetteIndexException::class);
        $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints());
    }

    public function testPropagatesAGenuineQueryFailure(): void
    {
        $index = new LogAnalyticsIndex(new LogAnalyticsIndexFailingQueryClient(), new LogAnalyticsIndexFakeObjectClient([]));

        $this->expectException(AzureStorageException::class);
        $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints());
    }
}

final class LogAnalyticsIndexFakeQueryClient implements AzureMonitorQueryClientInterface
{
    public ?string $lastQuery = null;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    #[\Override]
    public function query(string $kql): array
    {
        $this->lastQuery = $kql;

        return $this->rows;
    }
}

final class LogAnalyticsIndexFailingQueryClient implements AzureMonitorQueryClientInterface
{
    #[\Override]
    public function query(string $kql): array
    {
        throw new AzureStorageException('query failed');
    }
}

final class LogAnalyticsIndexFakeObjectClient implements ObjectStoreClientInterface
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
}
