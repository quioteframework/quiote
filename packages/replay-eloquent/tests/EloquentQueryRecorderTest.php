<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Adapter\Eloquent\EloquentMinimalEventDispatcher;
use Quiote\Replay\Adapter\Eloquent\EloquentQueryRecorder;
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
        (new EloquentQueryRecorder($ledger))->attach($conn);
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
        (new EloquentQueryRecorder($ledger))->attach($conn);
        $conn->statement('CREATE TABLE t (id INTEGER, name TEXT)');
        $conn->table('t')->insert(['id' => 1, 'name' => 'a']);

        $value = $conn->table('t')->where('id', 1)->value('name');

        $this->assertSame('a', $value);
    }

    public function testResultIsAlwaysNullSinceRowsAreNotObservableFromThisEvent(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder($ledger))->attach($conn);
        $conn->statement('CREATE TABLE t (id INTEGER)');
        $conn->table('t')->insert(['id' => 1]);

        $conn->table('t')->get();

        $selects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'select')));
        $this->assertCount(1, $selects);
        $this->assertNull($selects[0]->result);
    }

    public function testTwoSequentialQueriesProduceTwoOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder($ledger))->attach($conn);
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

        (new EloquentQueryRecorder(new EffectLedger()))->attach($conn);

        $this->assertNotNull($conn->getEventDispatcher());
    }

    public function testAttachReusesAnExistingEventDispatcher(): void
    {
        $conn = $this->connection();
        $existing = new EloquentMinimalEventDispatcher();
        $conn->setEventDispatcher($existing);

        (new EloquentQueryRecorder(new EffectLedger()))->attach($conn);

        $this->assertSame($existing, $conn->getEventDispatcher());
    }

    public function testAFailingStatementRecordsNothing(): void
    {
        $ledger = new EffectLedger();
        $conn = $this->connection();
        (new EloquentQueryRecorder($ledger))->attach($conn);

        try {
            $conn->select('select * from no_such_table');
            $this->fail('Expected a query exception.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame([], $ledger->all());
    }
}
