<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Store\Storage\Index\ExplicitKeyIndex;
use Quiote\Storage\ObjectStoreClientInterface;

final class ExplicitKeyIndexTest extends TestCase
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

    public function testDeclinesWhenNoKeyHintIsGiven(): void
    {
        $index = new ExplicitKeyIndex(new ExplicitKeyIndexFakeClient([]));

        $this->assertNull($index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints()));
    }

    public function testResolvesTheObjectAtTheGivenKey(): void
    {
        $codec = new CassetteCodec();
        $client = new ExplicitKeyIndexFakeClient(['prod/2026/08/18/09/CRX2050.qcast' => $codec->encode($this->cassette())]);
        $index = new ExplicitKeyIndex($client, $codec);

        $cassette = $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints(key: 'prod/2026/08/18/09/CRX2050.qcast'));

        $this->assertNotNull($cassette);
        $this->assertSame('CRX2050', $cassette->meta['id']);
    }

    public function testThrowsWhenTheGivenKeyDoesNotExist(): void
    {
        $index = new ExplicitKeyIndex(new ExplicitKeyIndexFakeClient([]));

        $this->expectException(CassetteIndexException::class);
        $index->resolve(CassetteId::fromRaw('CRX2050'), new IndexHints(key: 'prod/2026/08/18/09/CRX2050.qcast'));
    }
}

final class ExplicitKeyIndexFakeClient implements ObjectStoreClientInterface
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
    public function head(string $key): ?\Quiote\Storage\ObjectMetadata
    {
        throw new \LogicException('not used by this test');
    }
}
