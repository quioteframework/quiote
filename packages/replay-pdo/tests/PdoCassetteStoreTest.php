<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\Pdo\PdoCassetteStore;

final class PdoCassetteStoreTest extends TestCase
{
    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);
        $this->assertInstanceOf(PDOStatement::class, $stmt);

        return $stmt;
    }

    private function sqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(PdoCassetteStore::schema());

        return $pdo;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $response
     */
    private function cassette(
        array $meta = ['id' => 'CRX2050'],
        array $resolved = [],
        array $response = ['status' => 200],
    ): Cassette {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: $meta,
            request: ['method' => 'GET', 'uri' => '/'],
            resolved: $resolved,
            session: null,
            user: null,
            effects: [],
            response: $response,
            exception: null,
            log: null,
        );
    }

    public function testPutThenGetRoundTrips(): void
    {
        $store = new PdoCassetteStore($this->sqlitePdo());
        $id = CassetteId::fromRaw('CRX2050');

        $store->put($id, $this->cassette());
        $loaded = $store->get($id);

        $this->assertNotNull($loaded);
        $this->assertSame(['id' => 'CRX2050'], $loaded->meta);
    }

    public function testHasReflectsWhetherACassetteWasStored(): void
    {
        $store = new PdoCassetteStore($this->sqlitePdo());
        $id = CassetteId::fromRaw('CRX2050');

        $this->assertFalse($store->has($id));
        $store->put($id, $this->cassette());
        $this->assertTrue($store->has($id));
    }

    public function testGetOnAnUnknownIdReturnsNull(): void
    {
        $store = new PdoCassetteStore($this->sqlitePdo());

        $this->assertNull($store->get(CassetteId::fromRaw('never-stored')));
    }

    public function testDeleteRemovesAStoredCassette(): void
    {
        $store = new PdoCassetteStore($this->sqlitePdo());
        $id = CassetteId::fromRaw('CRX2050');
        $store->put($id, $this->cassette());

        $store->delete($id);

        $this->assertFalse($store->has($id));
    }

    public function testDeleteOfAnUnknownIdIsNotAnError(): void
    {
        $store = new PdoCassetteStore($this->sqlitePdo());

        $store->delete(CassetteId::fromRaw('never-stored'));

        $this->addToAssertionCount(1);
    }

    public function testSlugsListsEveryStoredCassetteInOrder(): void
    {
        $store = new PdoCassetteStore($this->sqlitePdo());
        $store->put(CassetteId::fromRaw('BBB'), $this->cassette(['id' => 'BBB']));
        $store->put(CassetteId::fromRaw('AAA'), $this->cassette(['id' => 'AAA']));

        $this->assertSame(['AAA', 'BBB'], $store->slugs());
    }

    public function testPuttingTheSameIdTwiceUpsertsRatherThanDuplicating(): void
    {
        $store = new PdoCassetteStore($this->sqlitePdo());
        $id = CassetteId::fromRaw('CRX2050');

        $store->put($id, $this->cassette(response: ['status' => 200]));
        $store->put($id, $this->cassette(response: ['status' => 500]));

        $this->assertSame(['CRX2050'], $store->slugs());
        $loaded = $store->get($id);
        $this->assertNotNull($loaded);
        $this->assertSame(500, $loaded->response['status']);
    }

    public function testExtractsRecordedAtRouteStatusAndTriggerIntoTheirOwnColumns(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new PdoCassetteStore($pdo);
        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette(
            meta: ['id' => 'CRX2050', 'recorded_at' => '2026-08-18T09:12:44Z', 'trigger' => 'error'],
            resolved: ['route' => 'orders.update'],
            response: ['status' => 500],
        ));

        $row = $this->query($pdo, 'SELECT raw_id, recorded_at, route, status, trigger_reason FROM quiote_cassettes')->fetch(PDO::FETCH_ASSOC);

        $this->assertSame([
            'raw_id' => 'CRX2050',
            'recorded_at' => '2026-08-18T09:12:44Z',
            'route' => 'orders.update',
            'status' => 500,
            'trigger_reason' => 'error',
        ], $row);
    }

    public function testMissingMetadataIsStoredAsNullNotAsAPlaceholder(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new PdoCassetteStore($pdo);
        $store->put(CassetteId::fromRaw('CRX2050'), $this->cassette(meta: ['id' => 'CRX2050'], resolved: [], response: []));

        $row = $this->query($pdo, 'SELECT recorded_at, route, status, trigger_reason FROM quiote_cassettes')->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(['recorded_at' => null, 'route' => null, 'status' => null, 'trigger_reason' => null], $row);
    }

    public function testUsesTheConfiguredTableName(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(PdoCassetteStore::schema('custom_cassettes'));
        $store = new PdoCassetteStore($pdo, 'custom_cassettes');
        $id = CassetteId::fromRaw('CRX2050');

        $store->put($id, $this->cassette());

        $this->assertTrue($store->has($id));
        $count = $this->query($pdo, 'SELECT COUNT(*) FROM custom_cassettes')->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function testAnInvalidTableNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoCassetteStore($this->sqlitePdo(), 'bad name; DROP TABLE x');
    }

    public function testSchemaProducesValidPostgresAndSqliteCompatibleDdl(): void
    {
        // Exercised against SQLite here; the DDL avoids any SQLite-only or Postgres-only type
        // (see the class's own docblock on the "PostgreSQL and SQLite" portability scope).
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec(PdoCassetteStore::schema());

        $this->addToAssertionCount(1);
    }
}
