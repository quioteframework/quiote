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
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM session')->fetchColumn());
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
}
