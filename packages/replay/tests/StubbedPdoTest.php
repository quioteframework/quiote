<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Db\RecordingPdoStatement;
use Quiote\Replay\Replay\StubbedPdo;
use Quiote\Replay\Replay\StubbedPdoStatement;

final class StubbedPdoTest extends TestCase
{
    public function testConstructingDoesNotAttemptARealConnection(): void
    {
        // If this ever called parent::__construct(), an invalid DSN would throw.
        $pdo = new StubbedPdo(new EffectLedger());

        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testQueryAnswersFromTheLedgerWithoutTouchingARealConnection(): void
    {
        $rows = [['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']];
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Db, 'SELECT id, name FROM t ORDER BY id', ['sql' => 'SELECT id, name FROM t ORDER BY id'], $rows),
        ]);
        $pdo = new StubbedPdo($ledger);

        $stmt = $pdo->query('SELECT id, name FROM t ORDER BY id');

        $this->assertSame($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testFetchLoopReturnsExactRecordedRowsInAssocNumAndObjModes(): void
    {
        $rows = [['id' => 1, 'name' => 'alice']];
        $sql = 'SELECT id, name FROM t';

        $assocLedger = new EffectLedger([new Effect(0, EffectKind::Db, $sql, [], $rows)]);
        $numLedger = new EffectLedger([new Effect(0, EffectKind::Db, $sql, [], $rows)]);
        $objLedger = new EffectLedger([new Effect(0, EffectKind::Db, $sql, [], $rows)]);

        $this->assertSame($rows, (new StubbedPdo($assocLedger))->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([[1, 'alice']], (new StubbedPdo($numLedger))->query($sql)->fetchAll(PDO::FETCH_NUM));
        $this->assertEquals([(object) ['id' => 1, 'name' => 'alice']], (new StubbedPdo($objLedger))->query($sql)->fetchAll(PDO::FETCH_OBJ));
    }

    public function testFetchOneRowAtATimeMatchesFetchAll(): void
    {
        $rows = [['id' => 1], ['id' => 2]];
        $ledger = new EffectLedger([new Effect(0, EffectKind::Db, 'SELECT id FROM t', [], $rows)]);
        $stmt = (new StubbedPdo($ledger))->prepare('SELECT id FROM t');
        $stmt->execute();

        $seen = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $seen[] = $row;
        }

        $this->assertSame($rows, $seen);
    }

    public function testExecAnswersFromARecordedRowCountEffect(): void
    {
        $ledger = new EffectLedger([new Effect(0, EffectKind::Db, "UPDATE t SET name = 'x'", ['sql' => "UPDATE t SET name = 'x'"], 3)]);
        $pdo = new StubbedPdo($ledger);

        $this->assertSame(3, $pdo->exec("UPDATE t SET name = 'x'"));
    }

    public function testRowCountReflectsTheRecordedRows(): void
    {
        $rows = [['id' => 1], ['id' => 2], ['id' => 3]];
        $ledger = new EffectLedger([new Effect(0, EffectKind::Db, 'SELECT id FROM t', [], $rows)]);
        $stmt = (new StubbedPdo($ledger))->prepare('SELECT id FROM t');
        $stmt->execute();

        $this->assertSame(3, $stmt->rowCount());
    }

    public function testAQueryWithNoMatchingLedgerEntryRaisesRatherThanReturningAnEmptyResult(): void
    {
        $pdo = new StubbedPdo(new EffectLedger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SELECT 1/');
        $pdo->query('SELECT 1');
    }

    public function testAnExhaustedLedgerRaisesOnTheSecondIdenticalCall(): void
    {
        $ledger = new EffectLedger([new Effect(0, EffectKind::Db, 'SELECT 1', [], [['x' => 1]])]);
        $pdo = new StubbedPdo($ledger);
        $pdo->query('SELECT 1')->fetchAll();

        $this->expectException(RuntimeException::class);
        $pdo->query('SELECT 1');
    }

    public function testExecRaisesWhenTheMatchedEffectIsARowSetNotACount(): void
    {
        $ledger = new EffectLedger([new Effect(0, EffectKind::Db, 'DELETE FROM t', [], [['id' => 1]])]);
        $pdo = new StubbedPdo($ledger);

        $this->expectException(RuntimeException::class);
        $pdo->exec('DELETE FROM t');
    }

    public function testFetchColumnIsAnsweredFromTheRecordedRows(): void
    {
        // Matches RecordingPdoStatement, which also answers it now: a stub that refuses what the
        // recorder accepts cannot replay code the recorder observed.
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Db, 'SELECT COUNT(*) FROM t', ['sql' => 'SELECT COUNT(*) FROM t'], [['c' => 7]]),
        ]);
        $stmt = new StubbedPdoStatement($ledger, 'SELECT COUNT(*) FROM t');
        $stmt->execute();

        $this->assertSame(7, $stmt->fetchColumn());
        $this->assertFalse($stmt->fetchColumn(), 'exhausted');
    }

    public function testAMalformedRecordedResultRaisesACassetteErrorNotATypeError(): void
    {
        // A cassette written by a recorder with a different result shape, or edited by hand. This
        // used to reach formatRow() through an unchecked @var and produce a raw TypeError.
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Db, 'SELECT x', ['sql' => 'SELECT x'], [1, 2, 3]),
        ]);
        $stmt = new StubbedPdoStatement($ledger, 'SELECT x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/neither a list of associative rows nor an affected-row count/');
        $stmt->execute();
    }

    public function testANullRecordedResultRaisesACassetteError(): void
    {
        // The shape quioteframework/replay-eloquent produces: its recorder cannot see rows at all,
        // so a cassette from it has nothing for this stub to answer with. Saying so beats silently
        // replaying every query as zero rows.
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Db, 'SELECT x', ['sql' => 'SELECT x'], null),
        ]);
        $stmt = new StubbedPdoStatement($ledger, 'SELECT x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/carries a null result/');
        $stmt->execute();
    }

    public function testBoundParametersParticipateInTheFingerprint(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Db, RecordingPdoStatement::fingerprintFor('SELECT n FROM t WHERE id = ?', [1 => 1]), ['sql' => 'SELECT n FROM t WHERE id = ?'], [['n' => 'one']]),
            new Effect(1, EffectKind::Db, RecordingPdoStatement::fingerprintFor('SELECT n FROM t WHERE id = ?', [1 => 2]), ['sql' => 'SELECT n FROM t WHERE id = ?'], [['n' => 'two']]),
        ]);

        // Asked for in the opposite order to the recording: only a parameter-aware fingerprint
        // gets each execution its own recorded rows.
        $second = new StubbedPdoStatement($ledger, 'SELECT n FROM t WHERE id = ?');
        $second->bindValue(1, 2);
        $second->execute();
        $this->assertSame([['n' => 'two']], $second->fetchAll(PDO::FETCH_ASSOC));

        $first = new StubbedPdoStatement($ledger, 'SELECT n FROM t WHERE id = ?');
        $first->bindValue(1, 1);
        $first->execute();
        $this->assertSame([['n' => 'one']], $first->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testFetchingBeforeExecuteRaises(): void
    {
        $stmt = (new StubbedPdo(new EffectLedger()))->prepare('SELECT id FROM t');

        $this->expectException(RuntimeException::class);
        $stmt->fetch();
    }
}
