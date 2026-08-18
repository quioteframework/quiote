<?php

declare(strict_types=1);

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\DoctrineRecordingMiddleware;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\FrozenClock;

final class DoctrineRecordingMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }
    }

    private function connect(EffectLedger $ledger): Connection
    {
        $config = new Configuration();
        $config->setMiddlewares([new DoctrineRecordingMiddleware($ledger, new FrozenClock())]);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
    }

    public function testASelectRecordsOneEffectWithTheFetchedRows(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connect($ledger);
        $conn->executeStatement('CREATE TABLE t (id INTEGER, name TEXT)');
        $conn->executeStatement("INSERT INTO t (id, name) VALUES (1, 'a')");

        $stmt = $conn->prepare('SELECT id, name FROM t WHERE id = ?');
        $stmt->bindValue(1, 1);
        $result = $stmt->executeQuery();
        $rows = $result->fetchAllAssociative();

        $this->assertSame([['id' => 1, 'name' => 'a']], $rows);

        $dbEffects = array_values(array_filter($ledger->all(), static fn($e) => $e->kind === EffectKind::Db && str_starts_with($e->fingerprint, 'SELECT')));
        $this->assertCount(1, $dbEffects);
        $this->assertSame([['id' => 1, 'name' => 'a']], $dbEffects[0]->result);
    }

    public function testTheCallerSeesTheRealRowsAfterRecording(): void
    {
        // Proves the connection is not left in a consumed state after the
        // recorder snapshots the result for the ledger.
        $ledger = new EffectLedger();
        $conn = $this->connect($ledger);
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');
        $conn->executeStatement('INSERT INTO t (id) VALUES (1), (2), (3)');

        $result = $conn->executeQuery('SELECT id FROM t ORDER BY id');
        $rows = $result->fetchAllAssociative();

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $rows);
    }

    public function testAnInsertRecordsTheAffectedRowCount(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connect($ledger);
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');

        $affected = $conn->executeStatement('INSERT INTO t (id) VALUES (1), (2)');

        $this->assertSame(2, $affected);
        $dbEffects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'INSERT')));
        $this->assertCount(1, $dbEffects);
        $this->assertSame(2, $dbEffects[0]->result);
    }

    public function testTwoSequentialQueriesProduceTwoOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connect($ledger);
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');

        $conn->executeQuery('SELECT 1');
        $conn->executeQuery('SELECT 2');

        $selects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'SELECT')));
        $this->assertCount(2, $selects);
        $this->assertLessThan($selects[1]->seq, $selects[0]->seq, 'the two SELECTs must be recorded in the order they ran');
    }

    public function testAFailingQueryDoesNotRecordAnEffectAndPropagates(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connect($ledger);

        try {
            $conn->executeQuery('SELECT * FROM no_such_table');
            $this->fail('Expected a DriverException.');
        } catch (DriverException) {
            // expected
        }

        $this->assertSame([], $ledger->all());
    }

    public function testBoundParametersAreCapturedInTheCallField(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connect($ledger);
        $conn->executeStatement('CREATE TABLE t (id INTEGER, name TEXT)');
        $conn->executeStatement("INSERT INTO t (id, name) VALUES (1, 'a')");

        $stmt = $conn->prepare('SELECT * FROM t WHERE id = ?');
        $stmt->bindValue(1, 1);
        $stmt->executeQuery();

        $effects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'SELECT')));
        $this->assertCount(1, $effects);
        $params = $effects[0]->call['params'];
        $this->assertIsArray($params);
        $this->assertSame(1, $params[1] ?? null);
    }
}
