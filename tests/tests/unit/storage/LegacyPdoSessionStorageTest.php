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
    /** @var list<string> Temporary SQLite files to remove after each test. */
    private array $sqliteFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->sqliteFiles as $path) {
            @unlink($path);
        }
        $this->sqliteFiles = [];

        parent::tearDown();
    }

    /** @param array<string, mixed> $parameters */
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
        $this->assertSame('foo|s:3:"bar";', $this->storedData($pdo, 'sid-new'));
    }

    public function testWriteUpdatesExistingSessionWithoutDuplicateRow(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);

        $storage->write('sid-1', 'foo|s:3:"bar";');
        $result = $storage->write('sid-1', 'foo|s:3:"baz";');

        $this->assertTrue($result);
        $this->assertSame(1, $this->rowCount($pdo, 'sid-1'));
        $this->assertSame('foo|s:3:"baz";', $this->storedData($pdo, 'sid-1'));
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

    /**
     * A read that leaves its cursor open keeps the connection inside an implicit
     * read transaction holding a shared lock, which blocks every *other*
     * connection from writing until the statement is closed or garbage
     * collected. In a worker runtime each process holds its own connection, so
     * one worker's unclosed read cursor makes the next worker's session write
     * fail with SQLITE_BUSY -- which is why the failure frequency observed in
     * the field tracked worker count. busy_timeout does not rescue this.
     */
    public function testReadClosesItsCursorSoAnotherConnectionCanStillWrite(): void
    {
        $path = $this->makeSqliteFile();
        $storage = $this->makeStorage($this->makeSqliteFilePdo($path), ['data_as_lob' => false]);
        $other = $this->makeSqliteFilePdo($path);

        $this->assertSame('payload', $storage->read('sid-1'));

        $other->exec("INSERT INTO session VALUES ('sid-2', 'written-by-another-worker', 1)");

        $this->assertSame('written-by-another-worker', $this->storedData($other, 'sid-2'));
    }

    /**
     * Same lock, reached through the class's own write path rather than a raw
     * second connection: read() on one storage instance must not wedge write()
     * on another sharing the same database file.
     */
    public function testReadOnOneConnectionDoesNotBlockWriteOnAnother(): void
    {
        $path = $this->makeSqliteFile();
        $reader = $this->makeStorage($this->makeSqliteFilePdo($path), ['data_as_lob' => false]);
        $writer = $this->makeStorage($this->makeSqliteFilePdo($path), ['data_as_lob' => false]);

        $reader->read('sid-1');

        $this->assertTrue($writer->write('sid-1', 'updated'));
        $this->assertSame('updated', $reader->read('sid-1'));
    }

    /**
     * read() caches its PDOStatement in $readStmt for the life of the instance,
     * so it must stay re-executable. PDO's SQLite driver happens to reset the
     * statement internally, so this passes with or without the closeCursor()
     * fix; it is a guard for the drivers that instead report "bad parameter or
     * other API misuse" (SQLSTATE HY000 / 21), which is how this surfaced in
     * the field under Swoole, where more requests share one long-lived
     * connection.
     */
    public function testCachedReadStatementCanBeReExecuted(): void
    {
        $pdo = $this->makeSqlitePdo();
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);
        $storage->write('sid-1', 'payload');

        $this->assertSame('payload', $storage->read('sid-1'));
        $this->assertSame('payload', $storage->read('sid-1'));
        $this->assertSame('', $storage->read('nonexistent'));
        $this->assertSame('payload', $storage->read('sid-1'));
    }

    /**
     * The cursor is released in a finally, so a read that throws does not leave
     * the connection wedged for the remaining life of a worker process.
     */
    public function testAFailingReadStillReleasesItsCursor(): void
    {
        $path = $this->makeSqliteFile();
        $pdo = $this->makeSqliteFilePdo($path);
        $storage = $this->makeStorage($pdo, ['data_as_lob' => false]);
        $other = $this->makeSqliteFilePdo($path);

        // Prime and cache $readStmt against the real table, then drop it so the
        // next execute() of that same cached statement fails inside read().
        $storage->read('sid-1');
        $other->exec('DROP TABLE session');

        try {
            $storage->read('sid-1');
            $this->fail('Expected the read against a dropped table to throw');
        } catch (\Quiote\Exception\DatabaseException) {
            // expected
        }

        $other->exec('CREATE TABLE session (sess_id TEXT PRIMARY KEY, sess_data TEXT NOT NULL, sess_time INTEGER NOT NULL)');
        $other->exec("INSERT INTO session VALUES ('sid-9', 'after-failure', 1)");

        $this->assertSame('after-failure', $this->storedData($other, 'sid-9'));
    }

    /**
     * Creates a file-backed SQLite database seeded with one session row, and
     * registers it for cleanup. A file (rather than ':memory:') is required:
     * an in-memory database is private to its connection, so the cross-
     * connection locking these tests exercise cannot occur there.
     */
    private function makeSqliteFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'quiote-session-lock-');
        if ($path === false) {
            $this->fail('Could not create a temporary SQLite file');
        }
        $this->sqliteFiles[] = $path;

        $pdo = $this->makeSqliteFilePdo($path);
        $pdo->exec('CREATE TABLE session (sess_id TEXT PRIMARY KEY, sess_data TEXT NOT NULL, sess_time INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO session VALUES ('sid-1', 'payload', 1)");

        return $path;
    }

    private function makeSqliteFilePdo(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Matches the field configuration these lock failures were reported
        // under, and demonstrates that busy_timeout does not cover the case.
        $pdo->exec('PRAGMA busy_timeout=2000');

        return $pdo;
    }

    /**
     * The stored session payload for $sid, or null when there is no such row.
     * PDO::query() is typed PDOStatement|false, so the narrowing lives here
     * once instead of at every call site.
     */
    private function storedData(\PDO $pdo, string $sid): ?string
    {
        $statement = $pdo->query('SELECT sess_data FROM session WHERE sess_id = ' . $pdo->quote($sid));
        $this->assertNotFalse($statement);
        $row = $statement->fetch(\PDO::FETCH_NUM);

        if (!is_array($row) || !array_key_exists(0, $row)) {
            return null;
        }

        return is_scalar($row[0]) ? (string) $row[0] : null;
    }

    private function rowCount(\PDO $pdo, string $sid): int
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM session WHERE sess_id = ' . $pdo->quote($sid));
        $this->assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function readPrivateStatement(PdoSessionStorage $storage, string $property): ?\PDOStatement
    {
        $ref = new ReflectionProperty(PdoSessionStorage::class, $property);
        $value = $ref->getValue($storage);
        return $value instanceof \PDOStatement ? $value : null;
    }
}
