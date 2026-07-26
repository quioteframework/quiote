<?php

use Quiote\Storage\PdoSessionStorage;
use Quiote\Testing\UnitTestCase;

/**
 * Covers Quiote\Storage\PdoSessionStorage (the original Storage-subsystem
 * implementation, distinct from packages/session-pdo's
 * Quiote\Storage\Pdo\PdoSessionStorage). write()'s upsert path (item 4a of
 * PERF_PLAN.md): writing an existing session must not rely on a failed
 * INSERT + caught PDOException + rollback + retry to update the row. Uses an
 * in-memory SQLite connection (driver name 'sqlite') injected directly via
 * reflection so these tests don't depend on a real database configuration.
 */
class LegacyPdoSessionStorageTest extends UnitTestCase
{
    private function makeStorage(\PDO $pdo, array $parameters = []): PdoSessionStorage
    {
        $storage = new PdoSessionStorage();
        $storage->initialize($this->getContext(), array_merge([
            'db_table' => 'session',
        ], $parameters));

        $ref = new ReflectionProperty(PdoSessionStorage::class, 'connection');
        $ref->setValue($storage, $pdo);

        return $storage;
    }

    private function makeSqlitePdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE session (sess_id TEXT PRIMARY KEY, sess_data TEXT NOT NULL, sess_time INTEGER NOT NULL)');
        return $pdo;
    }

    public function testWriteInsertsNewSession(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);

        $result = $storage->write('sid-new', 'foo|s:3:"bar";');

        $this->assertTrue($result);
        $row = $pdo->query('SELECT sess_data FROM session WHERE sess_id = ' . $pdo->quote('sid-new'))->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('foo|s:3:"bar";', $row['sess_data']);
    }

    public function testWriteUpdatesExistingSessionWithoutDuplicateRow(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);

        $storage->write('sid-1', 'foo|s:3:"bar";');
        $result = $storage->write('sid-1', 'foo|s:3:"baz";');

        $this->assertTrue($result);
        $rows = $pdo->query('SELECT sess_data FROM session WHERE sess_id = ' . $pdo->quote('sid-1'))->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('foo|s:3:"baz";', $rows[0]['sess_data']);
    }

    public function testWriteReusesPreparedStatementAcrossCalls(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);

        $storage->write('sid-1', 'first');
        $stmtAfterFirst = $this->readPrivateStatement($storage, 'writeStmt');

        $storage->write('sid-2', 'second');
        $stmtAfterSecond = $this->readPrivateStatement($storage, 'writeStmt');

        $this->assertNotNull($stmtAfterFirst);
        $this->assertSame($stmtAfterFirst, $stmtAfterSecond);
    }

    public function testReadReturnsWrittenData(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);

        $storage->write('sid-1', 'payload');

        $this->assertSame('payload', $storage->read('sid-1'));
    }

    public function testReadReturnsEmptyStringForUnknownSession(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);

        $this->assertSame('', $storage->read('nonexistent'));
    }

    private function readPrivateStatement(PdoSessionStorage $storage, string $property): ?\PDOStatement
    {
        $ref = new ReflectionProperty(PdoSessionStorage::class, $property);
        $value = $ref->getValue($storage);
        return $value instanceof \PDOStatement ? $value : null;
    }
}
