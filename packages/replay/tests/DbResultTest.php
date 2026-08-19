<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\DbResult;

/**
 * The per-kind contract for a database effect's `result`. Before it, each driver package answered
 * the same question in its own shape and every consumer was written against exactly one of them --
 * so a stub written for the PDO shape replayed an Eloquent cassette as zero rows for every query.
 * These pin both directions: the shape the recorders now write, and every legacy shape that must
 * still read back so cassettes recorded earlier stay usable.
 */
final class DbResultTest extends TestCase
{
    public function testARoundTripThroughTheArrayFormPreservesEveryField(): void
    {
        $result = new DbResult([['id' => 1]], 3, true);

        $read = DbResult::fromResult($result->toArray());

        $this->assertNotNull($read);
        $this->assertSame([['id' => 1]], $read->rows);
        $this->assertSame(3, $read->affectedRows);
        $this->assertTrue($read->rowsTruncated);
    }

    public function testNullRowsAndEmptyRowsAreDifferentAnswers(): void
    {
        // The distinction the whole class exists for: "the recorder cannot see the rows" is not
        // "the query returned nothing", and a stub has to refuse the first while replaying the
        // second.
        $unobserved = DbResult::unobservedRows();
        $empty = DbResult::rows([]);

        $this->assertNull($unobserved->rows);
        $this->assertSame([], $empty->rows);
    }

    public function testAWriteKeepsItsAffectedCountAndReportsNoRows(): void
    {
        $result = DbResult::affected(2);

        $this->assertSame(2, $result->affectedRows);
        $this->assertNull($result->rows);
        $this->assertFalse($result->rowsTruncated);
    }

    public function testAnUnobservableReadCanStillCarryARowCount(): void
    {
        // Cycle's shape: its logger seam reports how many rows there were without exposing them.
        $result = DbResult::unobservedRows(9);

        $this->assertNull($result->rows);
        $this->assertSame(9, $result->affectedRows);
    }

    public function testABareListOfRowsReadsBackAsTheLegacyPdoAndDoctrineReadShape(): void
    {
        $read = DbResult::fromResult([['id' => 1], ['id' => 2]]);

        $this->assertNotNull($read);
        $this->assertSame([['id' => 1], ['id' => 2]], $read->rows);
        $this->assertNull($read->affectedRows);
    }

    public function testABareIntReadsBackAsTheLegacyWriteShape(): void
    {
        $read = DbResult::fromResult(4);

        $this->assertNotNull($read);
        $this->assertSame(4, $read->affectedRows);
        $this->assertNull($read->rows);
    }

    public function testABareNullReadsBackAsTheLegacyEloquentShape(): void
    {
        $read = DbResult::fromResult(null);

        $this->assertNotNull($read);
        $this->assertNull($read->rows);
        $this->assertNull($read->affectedRows);
    }

    public function testThePropulsionShapeReadsBackWithItsRowsCountAndTruncationFlag(): void
    {
        $read = DbResult::fromResult([
            'row_count' => 5,
            'rows' => [['id' => 1]],
            'columns' => ['id'],
            'truncated' => true,
        ]);

        $this->assertNotNull($read);
        $this->assertSame([['id' => 1]], $read->rows);
        $this->assertSame(5, $read->affectedRows);
        $this->assertTrue($read->rowsTruncated);
    }

    public function testAValueThatDescribesNoDatabaseCallAtAllIsRefused(): void
    {
        $this->assertNull(DbResult::fromResult('a string'));
        $this->assertNull(DbResult::fromResult(1.5));
        $this->assertNull(DbResult::fromResult(true));
        // A list whose entries are not rows: the shape that used to reach formatRow() and raise a
        // TypeError.
        $this->assertNull(DbResult::fromResult([1, 2, 3]));
    }

    public function testAnAlreadyDecodedInstancePassesThrough(): void
    {
        $result = DbResult::rows([['id' => 1]]);

        $this->assertSame($result, DbResult::fromResult($result));
    }

    public function testRowsAreReindexedSoAFilteredSetStaysAList(): void
    {
        $result = DbResult::rows([1 => ['id' => 2], 3 => ['id' => 4]]);

        $this->assertSame([['id' => 2], ['id' => 4]], $result->rows);
    }

    public function testAKeyedShapeWithNoRowsKeyReportsUnobservableRows(): void
    {
        $read = DbResult::fromResult(['row_count' => 7]);

        $this->assertNotNull($read);
        $this->assertNull($read->rows);
        $this->assertSame(7, $read->affectedRows);
    }
}
