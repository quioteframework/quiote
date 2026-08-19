<?php

declare(strict_types=1);

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Adapter\Doctrine\DoctrineRecordingMiddleware;
use Quiote\Replay\Adapter\Doctrine\DoctrineSnapshotResult;
use Quiote\Replay\Recording\ActiveEffectLedger;
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

    protected function tearDown(): void
    {
        ActiveEffectLedger::reset();
    }

    private function connect(): Connection
    {
        $config = new Configuration();
        $config->setMiddlewares([new DoctrineRecordingMiddleware(new FrozenClock())]);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
    }

    public function testASelectRecordsOneEffectWithTheFetchedRows(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
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
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');
        $conn->executeStatement('INSERT INTO t (id) VALUES (1), (2), (3)');

        $result = $conn->executeQuery('SELECT id FROM t ORDER BY id');
        $rows = $result->fetchAllAssociative();

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $rows);
    }

    public function testAnInsertRecordsTheAffectedRowCount(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
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
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
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
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();

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
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
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

    public function testAQueryRunsUnrecordedWhenNoLedgerIsActive(): void
    {
        $conn = $this->connect();

        $conn->executeQuery('SELECT 1');

        // Nothing to assert against but "it didn't throw" -- there is no ledger to inspect,
        // which is the point: a boot-time query outside any recorded request is a no-op here.
        $this->addToAssertionCount(1);
    }

    public function testNoLedgerMeansTheRealResultIsHandedBackWithoutSnapshotting(): void
    {
        // The middleware is installed on the connection permanently, so an unconditional
        // snapshot materialized every result set in the application for the life of the worker.
        // Passing the real Result through is what keeps a streaming read streaming.
        $conn = $this->connect();
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');
        $conn->executeStatement('INSERT INTO t (id) VALUES (1), (2), (3)');

        $result = $conn->executeQuery('SELECT id FROM t ORDER BY id');

        $this->assertNotInstanceOf(DoctrineSnapshotResult::class, self::driverResultOf($result));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $result->fetchAllAssociative());
    }

    /**
     * The driver-level `Result` a `Doctrine\DBAL\Result` wraps. Read by reflection because DBAL
     * deliberately does not expose it -- and what it is, snapshot or real cursor, is exactly the
     * property under test here.
     */
    private static function driverResultOf(\Doctrine\DBAL\Result $result): object
    {
        $property = new ReflectionProperty(\Doctrine\DBAL\Result::class, 'result');
        $driverResult = $property->getValue($result);
        self::assertIsObject($driverResult);

        return $driverResult;
    }

    public function testNotRecordingDoesNotMaterializeALargeResultSet(): void
    {
        $conn = $this->connect();
        $conn->executeStatement('CREATE TABLE t (id INTEGER, v TEXT)');
        $insert = $conn->prepare('INSERT INTO t (id, v) VALUES (?, ?)');
        for ($i = 0; $i < 20_000; $i++) {
            $insert->bindValue(1, $i);
            $insert->bindValue(2, str_repeat('x', 200));
            $insert->executeStatement();
        }

        ActiveEffectLedger::set(null);
        $peakBefore = memory_get_peak_usage(true);
        $result = $conn->executeQuery('SELECT * FROM t');
        $streamed = 0;
        while ($result->fetchAssociative() !== false) {
            $streamed++;
        }
        $grew = memory_get_peak_usage(true) - $peakBefore;

        $this->assertSame(20_000, $streamed);
        // ~4 MiB of rows: snapshotting them costs several MiB of peak, streaming costs ~none.
        $this->assertLessThan(2_097_152, $grew, sprintf('Streaming an unrecorded result set grew peak memory by %d bytes.', $grew));
    }

    public function testRecordingStillSnapshotsSoTheCallerCanFetchAfterTheLedgerRead(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');
        $conn->executeStatement('INSERT INTO t (id) VALUES (7)');

        $result = $conn->executeQuery('SELECT id FROM t');

        // The ledger has already consumed the cursor, so only a snapshot can answer this.
        $this->assertSame([['id' => 7]], $result->fetchAllAssociative());
        $this->assertSame([['id' => 7]], $ledger->all()[count($ledger->all()) - 1]->result);
    }

    public function testAnEmptySelectReportsItsRealColumnCountWhileRecording(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
        $conn->executeStatement('CREATE TABLE t (id INTEGER, name TEXT)');

        $result = $conn->executeQuery('SELECT id, name FROM t WHERE id = 999');

        $this->assertSame([], $result->fetchAllAssociative());
        // The rows carry no column names to count, so the real result's own count is what
        // distinguishes "a SELECT that matched nothing" from "not a result set at all".
        $this->assertSame(2, $result->columnCount());
    }

    public function testASelectOfANullValueReportsNullNotFalseWhileRecording(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $conn = $this->connect();
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');

        $result = $conn->executeQuery('SELECT MAX(id) AS m FROM t');

        // A real driver result answers null here; false would mean "no rows", and there is a row.
        $this->assertNull($result->fetchOne());
    }

    public function testOneConnectionRecordsIntoWhicheverLedgerIsCurrentlyActive(): void
    {
        // The connection is built once and reused across "requests" here, mirroring how
        // DatabaseManager::recycleConnections() recycles (never rebuilds) a worker's Doctrine
        // connection -- proving ActiveEffectLedger, not a ledger fixed at connect() time, is
        // what makes a second request's queries land in that request's own cassette.
        $conn = $this->connect();
        $conn->executeStatement('CREATE TABLE t (id INTEGER)');

        $first = new EffectLedger();
        ActiveEffectLedger::set($first);
        $conn->executeQuery('SELECT 1');
        ActiveEffectLedger::set(null);

        $second = new EffectLedger();
        ActiveEffectLedger::set($second);
        $conn->executeQuery('SELECT 2');
        ActiveEffectLedger::set(null);

        $this->assertCount(1, $first->all());
        $this->assertCount(1, $second->all());
        $this->assertSame(['sql' => 'SELECT 1', 'params' => []], $first->all()[0]->call);
        $this->assertSame(['sql' => 'SELECT 2', 'params' => []], $second->all()[0]->call);
    }
}
