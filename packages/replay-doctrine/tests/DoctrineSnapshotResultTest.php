<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Adapter\Doctrine\DoctrineSnapshotResult;

final class DoctrineSnapshotResultTest extends TestCase
{
    public function testFetchAssociativeReturnsRowsInOrderThenFalse(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1], ['id' => 2]], 2);

        $this->assertSame(['id' => 1], $result->fetchAssociative());
        $this->assertSame(['id' => 2], $result->fetchAssociative());
        $this->assertFalse($result->fetchAssociative());
    }

    public function testFetchNumericReturnsValuesOnly(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1, 'name' => 'a']], 1);

        $this->assertSame([1, 'a'], $result->fetchNumeric());
    }

    public function testFetchOneReturnsTheFirstColumnValue(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1, 'name' => 'a']], 1);

        $this->assertSame(1, $result->fetchOne());
    }

    public function testFetchAllAssociativeReturnsOnlyUnconsumedRows(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1], ['id' => 2], ['id' => 3]], 3);

        $result->fetchAssociative();

        $this->assertSame([['id' => 2], ['id' => 3]], $result->fetchAllAssociative());
        $this->assertFalse($result->fetchAssociative());
    }

    public function testRowCountReturnsTheCapturedAffectedRows(): void
    {
        $result = new DoctrineSnapshotResult([], 5);

        $this->assertSame(5, $result->rowCount());
    }

    public function testColumnCountFallsBackToZeroForAnEmptyResultWithNoCapturedCount(): void
    {
        // No rows to read column names from and no real result to ask: zero is the only honest
        // answer. The recorder always passes the captured count, so this is the direct-construction
        // fallback rather than what a recorded query produces.
        $result = new DoctrineSnapshotResult([], 0);

        $this->assertSame(0, $result->columnCount());
    }

    public function testACapturedColumnCountAnswersForAnEmptyResultSet(): void
    {
        // A SELECT that matched nothing still has columns, and reporting zero is how a caller
        // tells a result set apart from a write -- Quiote\Replay\Db\RecordingPdoStatement
        // branches on exactly that.
        $result = new DoctrineSnapshotResult([], 0, 3);

        $this->assertSame(3, $result->columnCount());
    }

    public function testACapturedColumnCountTakesPrecedenceOverTheRowsOwnKeys(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1, 'name' => 'a']], 1, 2);

        $this->assertSame(2, $result->columnCount());
    }

    public function testFetchOneReturnsNullForARowWhoseFirstColumnIsNull(): void
    {
        // false means "no rows"; there is a row here, and its value is NULL. Collapsing the two
        // would make SELECT MAX(id) over an empty table indistinguishable from an exhausted
        // cursor for any caller comparing against false.
        $result = new DoctrineSnapshotResult([['m' => null]], 1);

        $this->assertNull($result->fetchOne());
    }

    public function testFetchOneReturnsFalseOnceTheRowsAreExhausted(): void
    {
        $result = new DoctrineSnapshotResult([['m' => null]], 1);

        $this->assertNull($result->fetchOne());
        $this->assertFalse($result->fetchOne());
    }

    public function testFetchOneReturnsFalseForAnEmptyResult(): void
    {
        $result = new DoctrineSnapshotResult([], 0);

        $this->assertFalse($result->fetchOne());
    }

    public function testFetchFirstColumnKeepsANullValueAsNull(): void
    {
        $result = new DoctrineSnapshotResult([['m' => null], ['m' => 2]], 2);

        $this->assertSame([null, 2], $result->fetchFirstColumn());
    }

    public function testColumnCountMatchesTheFirstRowsColumns(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1, 'name' => 'a']], 1);

        $this->assertSame(2, $result->columnCount());
    }

    public function testGetColumnNameReturnsTheColumnAtThatIndex(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1, 'name' => 'a']], 1);

        $this->assertSame('id', $result->getColumnName(0));
        $this->assertSame('name', $result->getColumnName(1));
    }

    public function testGetColumnNameThrowsForAnOutOfRangeIndex(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1]], 1);

        $this->expectException(\Doctrine\DBAL\Exception\InvalidColumnIndex::class);

        $result->getColumnName(5);
    }

    public function testFreeDiscardsRemainingRows(): void
    {
        $result = new DoctrineSnapshotResult([['id' => 1], ['id' => 2]], 2);

        $result->free();

        $this->assertSame([], $result->fetchAllAssociative());
    }
}
