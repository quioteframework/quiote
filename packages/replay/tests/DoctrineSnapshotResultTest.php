<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Db\DoctrineSnapshotResult;

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

    public function testColumnCountIsZeroForAnEmptyResult(): void
    {
        $result = new DoctrineSnapshotResult([], 0);

        $this->assertSame(0, $result->columnCount());
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
