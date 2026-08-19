<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\RecordingPdo;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\FrozenClock;

final class RecordingPdoTest extends TestCase
{
    private function query(RecordingPdo $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);
        $this->assertInstanceOf(PDOStatement::class, $stmt);

        return $stmt;
    }

    private function seededPdo(EffectLedger $ledger): RecordingPdo
    {
        $pdo = new RecordingPdo('sqlite::memory:', ledger: $ledger);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER, name TEXT)');
        $pdo->exec("INSERT INTO t (id, name) VALUES (1, 'alice')");
        $pdo->exec("INSERT INTO t (id, name) VALUES (2, 'bob')");

        return $pdo;
    }

    public function testSelectViaQueryRecordsOneDbEffectWithRowsAsResult(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $stmt = $this->query($pdo, 'SELECT id, name FROM t ORDER BY id');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame([['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']], $rows);

        $dbEffects = array_values(array_filter($ledger->all(), fn($e) => $e->kind === EffectKind::Db && str_starts_with($e->fingerprint, 'SELECT')));
        $this->assertCount(1, $dbEffects);
        $this->assertSame('SELECT id, name FROM t ORDER BY id', $dbEffects[0]->fingerprint);
        $this->assertSame([['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']], $dbEffects[0]->result);
        $this->assertSame('SELECT id, name FROM t ORDER BY id', $dbEffects[0]->call['sql']);
    }

    public function testFetchLoopAfterExecuteStillReturnsCorrectRows(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $stmt = $pdo->prepare('SELECT id, name FROM t ORDER BY id');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $stmt->execute();

        $seen = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $seen[] = $row;
        }

        $this->assertSame([['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']], $seen);
    }

    public function testFetchAllSupportsAssocNumAndObjModes(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $assoc = $this->query($pdo, 'SELECT id, name FROM t ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $num = $this->query($pdo, 'SELECT id, name FROM t ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $obj = $this->query($pdo, 'SELECT id, name FROM t ORDER BY id')->fetchAll(PDO::FETCH_OBJ);

        $this->assertSame([['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']], $assoc);
        $this->assertSame([[1, 'alice'], [2, 'bob']], $num);
        $this->assertEquals([(object) ['id' => 1, 'name' => 'alice'], (object) ['id' => 2, 'name' => 'bob']], $obj);
    }

    public function testExecRecordsTheAffectedRowCountWithoutBuildingAStatement(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $affected = $pdo->exec("UPDATE t SET name = 'carol' WHERE id = 1");

        $this->assertSame(1, $affected);
        $updateEffects = array_values(array_filter($ledger->all(), fn($e) => str_starts_with($e->fingerprint, 'UPDATE')));
        $this->assertCount(1, $updateEffects);
        $this->assertSame(1, $updateEffects[0]->result);
    }

    public function testTwoSequentialIdenticalQueriesProduceTwoSeparateLedgerEntriesInOrder(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $this->query($pdo, 'SELECT id FROM t WHERE id = 1')->fetchAll();
        $this->query($pdo, 'SELECT id FROM t WHERE id = 1')->fetchAll();

        $matching = array_values(array_filter($ledger->all(), fn($e) => $e->fingerprint === 'SELECT id FROM t WHERE id = 1'));
        $this->assertCount(2, $matching);
        $this->assertSame(0, $matching[0]->seq < $matching[1]->seq ? 0 : 1, 'entries must be in increasing seq order');
    }

    public function testAThrowingStatementPropagatesAndRecordsNoEffect(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $before = count($ledger->all());

        $this->expectException(PDOException::class);
        try {
            $pdo->query('SELECT * FROM no_such_table');
        } finally {
            $this->assertCount($before, $ledger->all(), 'a failed query must not record a bogus effect');
        }
    }

    public function testRowCountMatchesTheRealRowCountAfterBuffering(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $stmt = $this->query($pdo, 'SELECT id FROM t');

        $this->assertSame(2, $stmt->rowCount());
    }

    public function testDurationMicrosIsRecordedAndNonNegative(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $this->query($pdo, 'SELECT id FROM t')->fetchAll();

        $matching = array_values(array_filter($ledger->all(), fn($e) => $e->fingerprint === 'SELECT id FROM t'));
        $this->assertNotEmpty($matching);
        $this->assertNotNull($matching[0]->durationMicros);
        $this->assertGreaterThanOrEqual(0, $matching[0]->durationMicros);
    }

    public function testFetchColumnIsAnsweredFromTheSnapshot(): void
    {
        // Previously this threw, which broke the most common way to read a scalar aggregate --
        // installing the recorder turned working code into a RuntimeException.
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $stmt = $this->query($pdo, 'SELECT id FROM t ORDER BY id');

        $this->assertSame(1, $stmt->fetchColumn());
        $this->assertSame(2, $stmt->fetchColumn());
    }

    public function testFetchColumnReadsTheRequestedColumnIndex(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $stmt = $this->query($pdo, 'SELECT id, name FROM t ORDER BY id');

        $this->assertSame('alice', $stmt->fetchColumn(1));
    }

    public function testFetchColumnReturnsFalseOnceExhausted(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $stmt = $this->query($pdo, 'SELECT id FROM t WHERE id = 9999');

        $this->assertFalse($stmt->fetchColumn());
    }

    public function testBoundValuesAreCapturedInTheEffectCall(): void
    {
        // The common prepared-statement path binds through bindValue(), not through execute(),
        // so the recorded params were empty for most real queries.
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $stmt = $pdo->prepare('SELECT name FROM t WHERE id = :id');
        $stmt->bindValue(':id', 1, PDO::PARAM_INT);
        $stmt->execute();

        $effects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'SELECT name')));
        $this->assertCount(1, $effects);
        $params = $effects[0]->call['params'];
        $this->assertIsArray($params);
        $this->assertSame(1, $params[':id']);
    }

    public function testTheFingerprintDistinguishesTheSameSqlWithDifferentParameters(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $stmt = $pdo->prepare('SELECT name FROM t WHERE id = ?');

        $stmt->bindValue(1, 1, PDO::PARAM_INT);
        $stmt->execute();
        $stmt->bindValue(1, 2, PDO::PARAM_INT);
        $stmt->execute();

        $effects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'SELECT name')));
        $this->assertCount(2, $effects);
        $this->assertNotSame(
            $effects[0]->fingerprint,
            $effects[1]->fingerprint,
            'Two executions with different bound values must not fingerprint identically.',
        );
    }

    public function testDuplicateColumnNamesSurviveInPositionalFetchModes(): void
    {
        // An associative snapshot collapses these to one key, and every positional mode was then
        // rebuilt from the collapsed row -- wrong column count, wrong values.
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $stmt = $this->query($pdo, 'SELECT id, id FROM t ORDER BY id LIMIT 1');

        $row = $stmt->fetch(PDO::FETCH_NUM);
        $this->assertSame([1, 1], $row, 'Both columns must survive a FETCH_NUM read.');
    }

    public function testAReExecuteThatFailsDoesNotServeThePreviousRunsRows(): void
    {
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $stmt = $pdo->prepare('SELECT id FROM t WHERE id = ?');
        $stmt->bindValue(1, 1, PDO::PARAM_INT);
        $stmt->execute();
        $this->assertSame(['id' => 1], $stmt->fetch(PDO::FETCH_ASSOC));

        // Too few parameters: the re-execute fails, and the earlier snapshot must not answer.
        $failing = $pdo->prepare('SELECT id FROM t WHERE id = ? AND name = ?');
        $failing->execute();

        $this->assertFalse($failing->fetch(PDO::FETCH_ASSOC));
    }

    public function testTheSnapshotIsCappedAndSaysSo(): void
    {
        $ledger = new EffectLedger();
        $pdo = new RecordingPdo('sqlite::memory:', null, null, null, $ledger, new FrozenClock(), maxSnapshotRows: 3);
        $pdo->exec('CREATE TABLE big (id INTEGER)');
        for ($i = 1; $i <= 10; $i++) {
            $pdo->exec("INSERT INTO big (id) VALUES ($i)");
        }

        $stmt = $pdo->query('SELECT id FROM big ORDER BY id');
        $this->assertNotFalse($stmt);

        $effects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'SELECT id FROM big')));
        $this->assertCount(1, $effects);
        $result = $effects[0]->result;
        $this->assertIsArray($result);
        $this->assertTrue($result['rows_truncated']);
        $this->assertSame(3, $result['captured_row_count']);
        // The caller still receives every row that was captured.
        $this->assertCount(3, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testAResultSetExactlyAtTheCapIsNotReportedAsTruncated(): void
    {
        $ledger = new EffectLedger();
        $pdo = new RecordingPdo('sqlite::memory:', null, null, null, $ledger, new FrozenClock(), maxSnapshotRows: 3);
        $pdo->exec('CREATE TABLE exact (id INTEGER)');
        $pdo->exec('INSERT INTO exact (id) VALUES (1), (2), (3)');

        $pdo->query('SELECT id FROM exact ORDER BY id');

        $effects = array_values(array_filter($ledger->all(), static fn($e) => str_starts_with($e->fingerprint, 'SELECT id FROM exact')));
        $result = $effects[0]->result;
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('rows_truncated', $result);
        $this->assertCount(3, $result);
    }

    public function testAQueryPrepareCannotHandleStillRunsUnrecordedRatherThanFailing(): void
    {
        // Installing the recorder must never turn a working query into a broken one. A literal
        // placeholder inside a string is read as a parameter by prepare(), so this falls back.
        $ledger = new EffectLedger();
        $pdo = $this->seededPdo($ledger);

        $stmt = $pdo->query("SELECT '?' AS q");

        $this->assertNotFalse($stmt);
        $this->assertSame(['q' => '?'], $stmt->fetch(PDO::FETCH_ASSOC));
    }
}
