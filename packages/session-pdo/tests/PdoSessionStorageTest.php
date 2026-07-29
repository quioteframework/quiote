<?php

use PHPUnit\Framework\TestCase;
use Quiote\Storage\Pdo\PdoSessionStorage;

final class PdoSessionStorageTest extends TestCase
{
    private PDO $pdo;
    private PdoSessionStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time INTEGER NOT NULL)');

        // Bypass initialize()/open() (which reach into Context/DatabaseDriverRegistry, out
        // of scope for a storage-layer unit test) by injecting the connection directly.
        $this->storage = new PdoSessionStorage();
        $this->storage->setParameter('db_table', 'session');
        (new ReflectionProperty(PdoSessionStorage::class, 'connection'))->setValue($this->storage, $this->pdo);
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $this->storage->write('sid-1', 'serialized-payload');

        $this->assertSame('serialized-payload', $this->storage->read('sid-1'));
    }

    public function testWriteTwiceUpdatesInPlace(): void
    {
        $this->storage->write('sid-1', 'first');
        $this->storage->write('sid-1', 'second');

        $this->assertSame('second', $this->storage->read('sid-1'));

        $countStatement = $this->pdo->query('SELECT COUNT(*) FROM session');
        $this->assertNotFalse($countStatement);
        $this->assertSame(1, (int) $countStatement->fetchColumn());
    }

    public function testReadOfMissingSessionReturnsEmptyString(): void
    {
        $this->assertSame('', $this->storage->read('missing'));
    }

    public function testDestroyRemovesRow(): void
    {
        $this->storage->write('sid-1', 'data');
        $this->storage->destroy('sid-1');

        $this->assertSame('', $this->storage->read('sid-1'));
    }

    public function testGcDeletesExpiredRows(): void
    {
        $this->storage->write('old', 'data');
        $this->pdo->exec('UPDATE session SET sess_time = ' . (time() - 10_000) . " WHERE sess_id = 'old'");
        $this->storage->write('fresh', 'data');

        $deleted = $this->storage->gc(60);

        $this->assertSame(1, $deleted);
        $this->assertSame('', $this->storage->read('old'));
        $this->assertSame('data', $this->storage->read('fresh'));
    }

    /**
     * A read that leaves its cursor open keeps the connection inside an
     * implicit read transaction holding a shared lock, blocking every other
     * connection from writing; write() here opens an explicit transaction, so
     * its shared -> exclusive upgrade is exactly what SQLite refuses with
     * SQLITE_BUSY (busy_timeout deliberately does not cover upgrades).
     *
     * Unlike its siblings in Quiote\Storage and Quiote\Session, this read()
     * does not cache its statement, so refcounting frees the local PDOStatement
     * on return and releases the lock anyway -- these two tests therefore pass
     * with or without the explicit closeCursor(). They are here to pin the
     * invariant: the moment this class caches its read statement the way the
     * other two do, relying on GC timing stops working and these fail.
     *
     * Needs a file-backed database: ':memory:' is private per connection, so
     * the cross-connection lock cannot occur there.
     */
    public function testReadClosesItsCursorSoAnotherConnectionCanStillWrite(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'quiote-session-pdo-lock-');
        $this->assertIsString($path);

        try {
            $reader = $this->fileBackedStorage($path, seed: true);
            $writer = $this->fileBackedStorage($path);

            $this->assertSame('payload', $reader->read('sid-1'));

            $this->assertTrue($writer->write('sid-1', 'updated'));
            $this->assertSame('updated', $reader->read('sid-1'));
        } finally {
            @unlink($path);
        }
    }

    /**
     * The cursor is released in a finally, so a throwing read does not wedge
     * the connection for the remaining life of a worker process. See the note
     * on the test above for why this passes either way today.
     */
    public function testAFailingReadStillReleasesItsCursor(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'quiote-session-pdo-lock-');
        $this->assertIsString($path);

        try {
            $reader = $this->fileBackedStorage($path, seed: true);
            $other = $this->fileBackedPdo($path);

            $other->exec('DROP TABLE session');

            $this->expectedReadFailure($reader);

            $other->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time INTEGER NOT NULL)');
            $other->exec("INSERT INTO session VALUES ('sid-9', 'after-failure', 1)");

            $this->assertSame('after-failure', $reader->read('sid-9'));
        } finally {
            @unlink($path);
        }
    }

    private function expectedReadFailure(PdoSessionStorage $storage): void
    {
        try {
            $storage->read('sid-1');
            $this->fail('Expected the read against a dropped table to throw');
        } catch (\Throwable) {
            // expected
        }
    }

    private function fileBackedPdo(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout=2000');

        return $pdo;
    }

    private function fileBackedStorage(string $path, bool $seed = false): PdoSessionStorage
    {
        $pdo = $this->fileBackedPdo($path);
        if ($seed) {
            $pdo->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time INTEGER NOT NULL)');
            $pdo->exec("INSERT INTO session VALUES ('sid-1', 'payload', 1)");
        }

        $storage = new PdoSessionStorage();
        $storage->setParameter('db_table', 'session');
        (new ReflectionProperty(PdoSessionStorage::class, 'connection'))->setValue($storage, $pdo);

        return $storage;
    }
}
