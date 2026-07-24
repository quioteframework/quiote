<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Queue\Db\DbFailedJobStore;
use Quiote\Queue\FailedJob;
use Quiote\Queue\Job;

final class DbFailedJobStoreTestJob implements Job
{
    public function handle(): void
    {
    }
}

final class DbFailedJobStoreTest extends TestCase
{
    private function sqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(DbFailedJobStore::schema());
        return $pdo;
    }

    public function testRecordInsertsARow(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new DbFailedJobStore($pdo);

        $store->record(new FailedJob(
            jobClass: DbFailedJobStoreTestJob::class,
            params: ['userId' => 5],
            exceptionClass: RuntimeException::class,
            exceptionMessage: 'boom',
            exceptionTrace: '#0 ...',
            attempts: 3,
        ));

        $stmt = $pdo->query('SELECT * FROM quiote_queue_failed_jobs');
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame(DbFailedJobStoreTestJob::class, $row['job_class']);
        $this->assertSame(RuntimeException::class, $row['exception_class']);

        $params = $row['params'];
        if (!is_string($params)) {
            $this->fail('params column is not a string');
        }
        $this->assertSame(['userId' => 5], json_decode($params, true));

        $attempts = $row['attempts'];
        if (!is_int($attempts) && !is_string($attempts)) {
            $this->fail('attempts column is not numeric');
        }
        $this->assertSame(3, (int) $attempts);
    }

    public function testRecordCanBeCalledMultipleTimes(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new DbFailedJobStore($pdo);

        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'a', '', 1));
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'b', '', 1));

        $stmt = $pdo->query('SELECT COUNT(*) FROM quiote_queue_failed_jobs');
        $this->assertNotFalse($stmt);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testRejectsAnUnsafeTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $store = new DbFailedJobStore($this->sqlitePdo(), 'failed; DROP TABLE failed');
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'x', '', 1));
    }

    public function testCountReflectsRecordedRows(): void
    {
        $store = new DbFailedJobStore($this->sqlitePdo());
        $this->assertSame(0, $store->count());

        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'a', '', 1));
        $this->assertSame(1, $store->count());
    }

    public function testListReturnsAllRecordedRows(): void
    {
        // Both rows are recorded in the same second in a fast-running test, so
        // failed_at ties and the id (random hex) tiebreak doesn't reflect
        // insertion order -- assert membership, not a specific order.
        $pdo = $this->sqlitePdo();
        $store = new DbFailedJobStore($pdo);
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, ['userId' => 1], RuntimeException::class, 'a', '', 1));
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, ['userId' => 2], RuntimeException::class, 'b', '', 1));

        $records = $store->list();

        $this->assertCount(2, $records);
        $userIds = array_map(static fn($r) => $r->params['userId'], $records);
        sort($userIds);
        $this->assertSame([1, 2], $userIds);
    }

    public function testListRespectsLimitAndOffset(): void
    {
        $store = new DbFailedJobStore($this->sqlitePdo());
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'a', '', 1));
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'b', '', 1));
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'c', '', 1));

        $this->assertCount(1, $store->list(limit: 1));
        $this->assertCount(2, $store->list(limit: 10, offset: 1));
    }

    public function testFindReturnsNullForAnUnknownId(): void
    {
        $store = new DbFailedJobStore($this->sqlitePdo());

        $this->assertNull($store->find('nope'));
    }

    public function testFindReturnsTheMatchingRecord(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new DbFailedJobStore($pdo);
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, ['userId' => 9], RuntimeException::class, 'boom', '#0', 2));

        $stmt = $pdo->query('SELECT id FROM quiote_queue_failed_jobs');
        $this->assertNotFalse($stmt);
        $id = $stmt->fetchColumn();
        $this->assertIsString($id);

        $record = $store->find($id);

        $this->assertNotNull($record);
        $this->assertSame($id, $record->id);
        $this->assertSame(DbFailedJobStoreTestJob::class, $record->jobClass);
        $this->assertSame(['userId' => 9], $record->params);
        $this->assertSame(RuntimeException::class, $record->exceptionClass);
        $this->assertSame('boom', $record->exceptionMessage);
        $this->assertSame(2, $record->attempts);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $pdo = $this->sqlitePdo();
        $store = new DbFailedJobStore($pdo);
        $store->record(new FailedJob(DbFailedJobStoreTestJob::class, [], RuntimeException::class, 'a', '', 1));
        $stmt = $pdo->query('SELECT id FROM quiote_queue_failed_jobs');
        $this->assertNotFalse($stmt);
        $id = $stmt->fetchColumn();
        $this->assertIsString($id);

        $store->delete($id);

        $this->assertNull($store->find($id));
        $this->assertSame(0, $store->count());
    }
}
