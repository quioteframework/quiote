<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Adapter\Eloquent\EloquentMinimalEventDispatcher;
use Quiote\Replay\Adapter\Eloquent\EloquentQueryRecorder;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Replay\EffectLedger;

final class EloquentQueryRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            $this->markTestSkipped('illuminate/database not installed');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }
    }

    protected function tearDown(): void
    {
        ActiveEffectLedger::reset();
        EloquentQueryRecorder::reset();
    }

    private function connection(): \Illuminate\Database\Connection
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

        return $capsule->getConnection();
    }

    public function testASelectRecordsOneEffectWithSqlAndBindings(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder())->attach($conn);
        ActiveEffectLedger::set($ledger);
        $conn->statement('CREATE TABLE t (id INTEGER, name TEXT)');
        $conn->table('t')->insert(['id' => 1, 'name' => 'a']);

        $conn->table('t')->where('id', 1)->value('name');

        $selects = array_values(array_filter(
            $ledger->all(),
            static fn($e) => $e->kind === EffectKind::Db && str_starts_with($e->fingerprint, 'select'),
        ));
        $this->assertCount(1, $selects);
        $this->assertSame([1], $selects[0]->call['bindings']);
    }

    public function testTheCallerSeesTheRealResultUnaffected(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder())->attach($conn);
        ActiveEffectLedger::set($ledger);
        $conn->statement('CREATE TABLE t (id INTEGER, name TEXT)');
        $conn->table('t')->insert(['id' => 1, 'name' => 'a']);

        $value = $conn->table('t')->where('id', 1)->value('name');

        $this->assertSame('a', $value);
    }

    public function testRowsAreRecordedAsUnobservableRatherThanAsEmpty(): void
    {
        // This event fires after the rows have already gone back to the caller, so there is nothing
        // to capture. Recording that as "no rows" would be a lie a replay stub could not tell from
        // a query that genuinely returned nothing -- it would replay every read as empty.
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder())->attach($conn);
        ActiveEffectLedger::set($ledger);
        $conn->statement('CREATE TABLE t (id INTEGER)');
        $conn->table('t')->insert(['id' => 1]);

        $conn->table('t')->get();

        $selects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'select')));
        $this->assertCount(1, $selects);
        $recorded = DbResult::fromResult($selects[0]->result);
        $this->assertNotNull($recorded);
        $this->assertNull($recorded->rows, 'null rows means "not observable", distinct from an empty list.');
        $this->assertNull($recorded->affectedRows);
    }

    public function testTwoSequentialQueriesProduceTwoOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder())->attach($conn);
        ActiveEffectLedger::set($ledger);
        $conn->statement('CREATE TABLE t (id INTEGER)');

        $conn->select('select 1');
        $conn->select('select 2');

        $selects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'select')));
        $this->assertCount(2, $selects);
        $this->assertLessThan($selects[1]->seq, $selects[0]->seq);
    }

    public function testAttachInstallsAFallbackDispatcherWhenNoneExists(): void
    {
        $conn = $this->connection();
        $this->assertNull($conn->getEventDispatcher());

        (new EloquentQueryRecorder())->attach($conn);

        $this->assertNotNull($conn->getEventDispatcher());
    }

    public function testAttachReusesAnExistingEventDispatcher(): void
    {
        $conn = $this->connection();
        $existing = new EloquentMinimalEventDispatcher();
        $conn->setEventDispatcher($existing);

        (new EloquentQueryRecorder())->attach($conn);

        $this->assertSame($existing, $conn->getEventDispatcher());
    }

    public function testAFailingStatementRecordsNothing(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder())->attach($conn);
        ActiveEffectLedger::set($ledger);

        try {
            $conn->select('select * from no_such_table');
            $this->fail('Expected a query exception.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame([], $ledger->all());
    }

    public function testAQueryRunsUnrecordedWhenNoLedgerIsActive(): void
    {
        $conn = $this->connection();
        (new EloquentQueryRecorder())->attach($conn);

        $conn->select('select 1');

        $this->addToAssertionCount(1);
    }

    public function testOneConnectionRecordsIntoWhicheverLedgerIsCurrentlyActive(): void
    {
        // attach() runs once, mirroring how EloquentDatabase::connect() only builds the
        // connection once and DatabaseManager::recycleConnections() reuses it thereafter --
        // proving ActiveEffectLedger, not a ledger fixed at attach() time, is what makes a
        // second request's queries land in that request's own cassette.
        $conn = $this->connection();
        (new EloquentQueryRecorder())->attach($conn);
        $conn->statement('CREATE TABLE t (id INTEGER)');

        $first = new EffectLedger();
        ActiveEffectLedger::set($first);
        $conn->select('select 1');
        ActiveEffectLedger::set(null);

        $second = new EffectLedger();
        ActiveEffectLedger::set($second);
        $conn->select('select 2');
        ActiveEffectLedger::set(null);

        $this->assertCount(1, $first->all());
        $this->assertCount(1, $second->all());
        $this->assertSame('select 1', $first->all()[0]->call['sql']);
        $this->assertSame('select 2', $second->all()[0]->call['sql']);
    }

    public function testAttachingTwiceToOneConnectionRecordsEachQueryOnce(): void
    {
        // connect() runs again on a reconnect -- the case a long-running worker exists to handle --
        // and Connection::listen() appends unconditionally, so every reconnect used to leave one
        // more listener and multiply every effect.
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $connection = $this->connection();

        (new EloquentQueryRecorder())->attach($connection);
        (new EloquentQueryRecorder())->attach($connection);
        (new EloquentQueryRecorder())->attach($connection);

        $connection->select('select 1');

        $this->assertCount(1, $ledger->all(), 'Three attaches must still record one effect per query.');
    }

    public function testTwoDistinctConnectionsAreEachAttachedTo(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $first = $this->connection();
        $second = $this->connection();

        (new EloquentQueryRecorder())->attach($first);
        (new EloquentQueryRecorder())->attach($second);

        $first->select('select 1');
        $second->select('select 2');

        $this->assertCount(2, $ledger->all());
    }
}
