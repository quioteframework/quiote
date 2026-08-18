<?php

declare(strict_types=1);

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\CycleRecordingLogger;
use Quiote\Replay\Replay\EffectLedger;

final class CycleRecordingLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(DatabaseManager::class)) {
            $this->markTestSkipped('cycle/database not installed');
        }
    }

    private function database(EffectLedger $ledger): DatabaseInterface
    {
        $manager = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'sqlite']],
            'connections' => ['sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig())],
        ]));
        $manager->setLogger(new CycleRecordingLogger($ledger));

        return $manager->database('default');
    }

    public function testASelectRecordsOneEffectWithRowCountAndTiming(): void
    {
        $ledger = new EffectLedger();
        $db = $this->database($ledger);
        $db->execute('CREATE TABLE t (id INTEGER, name TEXT)');
        $db->execute("INSERT INTO t (id, name) VALUES (1, 'a')");

        $rows = $db->query('SELECT id, name FROM t')->fetchAll();

        $this->assertSame([['id' => 1, 'name' => 'a']], $rows);

        $selects = array_values(array_filter(
            $ledger->all(),
            static fn($e) => $e->kind === EffectKind::Db && str_starts_with($e->fingerprint, 'SELECT'),
        ));
        $this->assertCount(1, $selects);
        // PDOStatement::rowCount() for a SELECT is driver-dependent (often 0 for
        // sqlite) -- only that it is a captured int matters here, not its value.
        $this->assertIsInt($selects[0]->result);
        $this->assertNotNull($selects[0]->durationMicros);
    }

    public function testAnInsertRecordsTheAffectedRowCount(): void
    {
        $ledger = new EffectLedger();
        $db = $this->database($ledger);
        $db->execute('CREATE TABLE t (id INTEGER)');

        $affected = $db->execute('INSERT INTO t (id) VALUES (1), (2)');

        $this->assertSame(2, $affected);
        $inserts = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'INSERT')));
        $this->assertCount(1, $inserts);
        $this->assertSame(2, $inserts[0]->result);
    }

    public function testTwoSequentialQueriesProduceTwoOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        $db = $this->database($ledger);
        $db->execute('CREATE TABLE t (id INTEGER)');

        $db->query('SELECT 1')->fetchAll();
        $db->query('SELECT 2')->fetchAll();

        $selects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'SELECT')));
        $this->assertCount(2, $selects);
        $this->assertLessThan($selects[1]->seq, $selects[0]->seq);
    }

    public function testAFailingQueryDoesNotRecordAnEffectAndPropagates(): void
    {
        $ledger = new EffectLedger();
        $db = $this->database($ledger);

        try {
            $db->query('SELECT * FROM no_such_table')->fetchAll();
            $this->fail('Expected a statement exception.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame([], $ledger->all());
    }

    public function testOtherLogLevelsAreIgnored(): void
    {
        $ledger = new EffectLedger();
        $logger = new CycleRecordingLogger($ledger);

        $logger->error('boom');
        $logger->warning('careful');
        $logger->debug('detail');

        $this->assertSame([], $ledger->all());
    }
}
