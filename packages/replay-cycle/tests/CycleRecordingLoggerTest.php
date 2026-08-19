<?php

declare(strict_types=1);

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\DbResult;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Adapter\Cycle\CycleRecordingLogger;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Replay\EffectLedger;

final class CycleRecordingLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(DatabaseManager::class)) {
            $this->markTestSkipped('cycle/database not installed');
        }
    }

    protected function tearDown(): void
    {
        ActiveEffectLedger::reset();
    }

    private function database(): DatabaseInterface
    {
        $manager = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'sqlite']],
            'connections' => ['sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig())],
        ]));
        $manager->setLogger(new CycleRecordingLogger());

        return $manager->database('default');
    }

    public function testASelectRecordsOneEffectWithRowCountAndTiming(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $db = $this->database();
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
        // sqlite) -- only that it is a captured int matters here, not its value. The rows
        // themselves are never observable through Cycle's logger seam, which is recorded as
        // null rather than as an empty list.
        $recorded = DbResult::fromResult($selects[0]->result);
        $this->assertNotNull($recorded);
        $this->assertIsInt($recorded->affectedRows);
        $this->assertNull($recorded->rows);
        $this->assertNotNull($selects[0]->durationMicros);
    }

    public function testAnInsertRecordsTheAffectedRowCount(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $db = $this->database();
        $db->execute('CREATE TABLE t (id INTEGER)');

        $affected = $db->execute('INSERT INTO t (id) VALUES (1), (2)');

        $this->assertSame(2, $affected);
        $inserts = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'INSERT')));
        $this->assertCount(1, $inserts);
        $this->assertSame(2, DbResult::fromResult($inserts[0]->result)?->affectedRows);
    }

    public function testTwoSequentialQueriesProduceTwoOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $db = $this->database();
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
        ActiveEffectLedger::set($ledger);
        $db = $this->database();

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
        ActiveEffectLedger::set($ledger);
        $logger = new CycleRecordingLogger();

        $logger->error('boom');
        $logger->warning('careful');
        $logger->debug('detail');

        $this->assertSame([], $ledger->all());
    }

    public function testAQueryRunsUnrecordedWhenNoLedgerIsActive(): void
    {
        $db = $this->database();

        $db->query('SELECT 1')->fetchAll();

        $this->addToAssertionCount(1);
    }

    public function testOneConnectionRecordsIntoWhicheverLedgerIsCurrentlyActive(): void
    {
        // setLogger() runs once, mirroring how CycleDatabase::connect() only builds the
        // DatabaseManager once and DatabaseManager::recycleConnections() reuses it thereafter --
        // proving ActiveEffectLedger, not a ledger fixed at setLogger() time, is what makes a
        // second request's queries land in that request's own cassette.
        $db = $this->database();
        $db->execute('CREATE TABLE t (id INTEGER)');

        $first = new EffectLedger();
        ActiveEffectLedger::set($first);
        $db->query('SELECT 1')->fetchAll();
        ActiveEffectLedger::set(null);

        $second = new EffectLedger();
        ActiveEffectLedger::set($second);
        $db->query('SELECT 2')->fetchAll();
        ActiveEffectLedger::set(null);

        $this->assertCount(1, $first->all());
        $this->assertCount(1, $second->all());
        $this->assertSame('SELECT 1', $first->all()[0]->call['sql']);
        $this->assertSame('SELECT 2', $second->all()[0]->call['sql']);
    }

    public function testTheRecordingLoggerForwardsEveryMessageToTheApplicationsOwnLogger(): void
    {
        // setLogger() is a whole-value assignment on Cycle's DatabaseManager, so installing the
        // recorder must not silently end the application's own query logging.
        $inner = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{0: mixed, 1: string}> */
            public array $seen = [];

            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->seen[] = [$level, (string)$message];
            }
        };
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);
        $logger = CycleRecordingLogger::wrapping($inner);

        $logger->info('select 1', ['rowCount' => 1, 'elapsed' => 0.001]);
        $logger->error('select bad', []);

        $this->assertSame([['info', 'select 1'], ['error', 'select bad']], $inner->seen);
        // And still records the successful query itself.
        $this->assertCount(1, $ledger->all());
    }

    public function testWrappingAnotherRecordingLoggerDoesNotChainThem(): void
    {
        // A second connect() on the same manager would otherwise nest recorders and double every
        // effect.
        $first = CycleRecordingLogger::wrapping(null);
        $second = CycleRecordingLogger::wrapping($first);
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);

        $second->info('select 1', ['rowCount' => 1, 'elapsed' => 0.001]);

        $this->assertCount(1, $ledger->all());
    }

    public function testWithNoExistingLoggerNothingIsForwardedAndRecordingStillWorks(): void
    {
        $ledger = new EffectLedger();
        ActiveEffectLedger::set($ledger);

        CycleRecordingLogger::wrapping(null)->info('select 1', ['rowCount' => 1, 'elapsed' => 0.001]);

        $this->assertCount(1, $ledger->all());
    }
}
