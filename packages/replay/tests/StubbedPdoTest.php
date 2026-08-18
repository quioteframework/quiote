<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\StubbedPdo;

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

    public function testFetchColumnIsUnsupported(): void
    {
        $ledger = new EffectLedger([new Effect(0, EffectKind::Db, 'SELECT id FROM t', [], [['id' => 1]])]);
        $stmt = (new StubbedPdo($ledger))->prepare('SELECT id FROM t');
        $stmt->execute();

        $this->expectException(RuntimeException::class);
        $stmt->fetchColumn();
    }

    public function testFetchingBeforeExecuteRaises(): void
    {
        $stmt = (new StubbedPdo(new EffectLedger()))->prepare('SELECT id FROM t');

        $this->expectException(RuntimeException::class);
        $stmt->fetch();
    }
}
